#!/usr/bin/env python3
"""Server conformance: checks any PAP/1 server against PROTOCOL.md.

    # a server with the test admin API (creates its own agent through it),
    # e.g. examples/standalone of printdock-php:
    python3 server_conformance.py --admin-url http://127.0.0.1:8090/admin

    # any server: you provide the key, the printer id and the lease length;
    # job-dependent checks need a way to enqueue a job for that printer:
    python3 server_conformance.py --url https://wms.example/api/printing/agent/v1 \\
        --key pa1_... --printer-id pr_label1 --lease-s 60 \\
        --enqueue-cmd 'php bin/console printing:enqueue --printer={printer_id} --file={file}'

``--enqueue-cmd`` is a shell template; ``{printer_id}`` and ``{file}`` (a ZPL file)
are substituted and the command must print the new job_id on stdout. Checks
that need server-side knobs (expiry, content_url, revocation) run only with the
test admin API and are reported as SKIP elsewhere.
Python 3.9+, standard library only.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import platform
import re
import shlex
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from typing import Any, Callable, Dict, List, Optional, Tuple

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ZPL = b"^XA^PW812^LL1218^FO40,40^A0N,60,60^FDPAP/1 CONFORMANCE^FS^XZ"
ISO = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$")


class Fail(Exception):
    pass


class Skip(Exception):
    pass


class Pap:
    """Minimal PAP client that records every response for cross-cutting checks."""

    def __init__(self, url: str, key: str) -> None:
        self.url = url.rstrip("/")
        self.key = key
        self.responses: List[dict] = []

    def call(self, method: str, path: str, body: Optional[dict] = None, key: Optional[str] = "default",
             version: Optional[str] = "1", absolute: bool = False, extra_headers: Optional[dict] = None
             ) -> Tuple[int, Dict[str, str], Any]:
        headers = {"User-Agent": "pap-conformance/1.0 (%s)" % platform.system().lower(),
                   "Content-Type": "application/json"}
        if key == "default":
            headers["Authorization"] = "Bearer " + self.key
        elif key:
            headers["Authorization"] = "Bearer " + key
        if version is not None:
            headers["X-PAP-Version"] = version
        headers.update(extra_headers or {})
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(path if absolute else self.url + path, data=data, method=method, headers=headers)
        try:
            with urllib.request.urlopen(req, timeout=20) as resp:
                status, hdrs, raw = resp.status, dict(resp.headers), resp.read()
        except urllib.error.HTTPError as exc:
            status, hdrs, raw = exc.code, dict(exc.headers), exc.read()
        payload: Any = raw
        if "json" in hdrs.get("Content-Type", ""):
            try:
                payload = json.loads(raw.decode("utf-8")) if raw else {}
            except ValueError:
                payload = {"_unparseable": raw[:100].decode("latin-1")}
        self.responses.append({"method": method, "path": path, "status": status, "headers": hdrs,
                               "json": payload if isinstance(payload, dict) else None})
        return status, hdrs, payload

    def hello(self, **extra: Any) -> Tuple[int, Dict[str, str], Any]:
        body = {"agent": {"version": "conformance", "os": "linux", "arch": "amd64", "hostname": "conformance"},
                "capabilities": {"transports": ["socket"], "formats": ["zpl", "raw"]}, "seen_printers": []}
        body.update(extra)
        return self.call("POST", "/hello", body)

    def claim(self, printer_ids: List[str]) -> Tuple[int, Dict[str, str], Any]:
        """A claim as a well-behaved agent makes it: a server may rate-limit a claim that follows an empty one too
        soon (PROTOCOL 5.3, ``429`` with ``Retry-After``); the suite then waits as told and asks again, so it
        passes against a server with that cooldown on."""
        for _ in range(5):
            status, hdrs, body = self.call("POST", "/jobs/claim", {"printer_ids": printer_ids, "max": 1})
            if status != 429:
                return status, hdrs, body
            try:
                wait = float(hdrs.get("Retry-After") or 1)
            except ValueError:
                wait = 1.0
            time.sleep(min(max(wait, 0.5), 10.0))
        return status, hdrs, body

    def ack(self, job_id: str, token: Optional[str], outcome: str, **extra: Any) -> Tuple[int, Dict[str, str], Any]:
        body = {"lease_token": token, "outcome": outcome, "agent_time": "2026-01-01T00:00:00Z"}
        body.update(extra)
        return self.call("POST", "/jobs/%s/ack" % job_id, body)


class JobSource:
    """Creates jobs on the server under test: through the reference admin API or a shell command."""

    def __init__(self, admin_url: Optional[str], enqueue_cmd: Optional[str], agent_id: Optional[str],
                 printer_id: str) -> None:
        self.admin_url = admin_url.rstrip("/") if admin_url else None
        self.enqueue_cmd = enqueue_cmd
        self.agent_id = agent_id
        self.printer_id = printer_id

    def admin(self, method: str, path: str, body: Optional[dict] = None) -> Any:
        req = urllib.request.Request(self.admin_url + path, data=json.dumps(body).encode() if body is not None else None,
                                     method=method, headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=10) as resp:
            raw = resp.read()
        return json.loads(raw) if raw else {}

    @property
    def available(self) -> bool:
        return bool(self.admin_url or self.enqueue_cmd)

    @property
    def has_admin(self) -> bool:
        return bool(self.admin_url)

    def new_job(self, content: bytes = ZPL, **opts: Any) -> str:
        if self.admin_url:
            spec = {"agent_id": self.agent_id, "printer_id": self.printer_id, "format": "zpl",
                    "content_base64": base64.b64encode(content).decode(), "title": "conformance"}
            spec.update(opts)
            return self.admin("POST", "/jobs", spec)["job_id"]
        if opts:
            raise Skip("needs the reference admin API (%s)" % ", ".join(opts))
        if not self.enqueue_cmd:
            raise Skip("no way to enqueue a job (--admin-url or --enqueue-cmd)")
        with tempfile.NamedTemporaryFile("wb", suffix=".zpl", delete=False) as fh:
            fh.write(content)
            path = fh.name
        cmd = self.enqueue_cmd.format(printer_id=shlex.quote(self.printer_id), file=shlex.quote(path))
        out = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
        if out.returncode != 0:
            raise Fail("enqueue command failed: %s" % out.stderr.decode(errors="replace")[:200])
        job_id = out.stdout.decode().strip().splitlines()[-1].strip()
        if not job_id:
            raise Fail("enqueue command printed no job_id")
        return job_id


CHECKS: List[dict] = []


def check(name: str, needs_jobs: bool = False):
    def deco(fn: Callable[["Ctx"], Optional[str]]):
        CHECKS.append({"name": name, "fn": fn, "needs_jobs": needs_jobs, "doc": (fn.__doc__ or "").strip()})
        return fn
    return deco


class Ctx:
    def __init__(self, pap: Pap, jobs: JobSource, printer_id: str, lease_s: float) -> None:
        self.pap = pap
        self.jobs = jobs
        self.printer_id = printer_id
        self.lease_s = lease_s
        self.hello_payload: Optional[dict] = None

    def drain(self, max_jobs: int = 20) -> int:
        """Reject whatever is queued for our printer so that checks start clean."""
        n = 0
        for _ in range(max_jobs):
            status, _, job = self.pap.claim([self.printer_id])
            if status != 200:
                break
            self.pap.ack(job["job_id"], job.get("lease_token"), "rejected", reason="unsupported_route",
                         detail="conformance drain")
            n += 1
        return n

    def claim_job(self, expect_id: Optional[str] = None, tries: int = 10) -> dict:
        for _ in range(tries):
            status, _, job = self.pap.claim([self.printer_id])
            if status == 200:
                if expect_id and job.get("job_id") != expect_id:
                    self.pap.ack(job["job_id"], job.get("lease_token"), "rejected", reason="unsupported_route",
                                 detail="conformance: unexpected job")
                    continue
                return job
            if status != 204:
                raise Fail("claim returned HTTP %d %s" % (status, job))
            time.sleep(0.5)
        raise Fail("claim never returned job %s" % expect_id)


def expect(cond: bool, msg: str) -> None:
    if not cond:
        raise Fail(msg)


# ---------------------------------------------------------------------------

@check("version_header")
def c_version_header(ctx: Ctx) -> str:
    """Every PAP response carries X-PAP-Version: 1, including errors."""
    status, hdrs, _ = ctx.pap.hello()
    expect(hdrs.get("X-PAP-Version", "").split(".")[0] == "1", "hello response lacks X-PAP-Version: 1")
    status, hdrs, _ = ctx.pap.call("POST", "/hello", {}, key="pa1_nope0000_bad")
    expect(hdrs.get("X-PAP-Version", "").split(".")[0] == "1", "401 response lacks X-PAP-Version: 1")
    return "present on 200 and on 401"


@check("unauthorized")
def c_unauthorized(ctx: Ctx) -> str:
    """Unknown or missing key -> 401 {"error":"unauthorized"}."""
    status, _, body = ctx.pap.call("POST", "/hello", {}, key="pa1_nope0000_" + "A" * 43)
    expect(status == 401, "unknown key: HTTP %d, expected 401" % status)
    expect(isinstance(body, dict) and body.get("error") == "unauthorized", "unknown key: error=%r" % body)
    status, _, body = ctx.pap.call("POST", "/hello", {}, key=None)
    expect(status == 401, "missing key: HTTP %d, expected 401" % status)
    return "401 unauthorized for unknown and missing key"


@check("unsupported_version")
def c_bad_version(ctx: Ctx) -> str:
    """X-PAP-Version 2 or missing -> 400 unsupported_version."""
    status, _, body = ctx.pap.call("POST", "/jobs/claim", {"printer_ids": [ctx.printer_id]}, version="2")
    expect(status == 400 and body.get("error") == "unsupported_version", "version 2: HTTP %d %s" % (status, body))
    status, _, body = ctx.pap.call("POST", "/jobs/claim", {"printer_ids": [ctx.printer_id]}, version=None)
    expect(status == 400 and body.get("error") == "unsupported_version", "no version: HTTP %d %s" % (status, body))
    return "400 unsupported_version for 2 and for missing header"


@check("hello_shape")
def c_hello(ctx: Ctx) -> str:
    """hello returns the station configuration with all required fields."""
    status, _, body = ctx.pap.hello(unknown_field_for_forward_compat=True)
    expect(status == 200, "hello HTTP %d %s" % (status, body))
    for key in ("agent_id", "poll_interval_s", "hello_interval_s", "lease_s", "spool_timeout_s",
                "printers_managed_by", "config_version", "printers", "server_capabilities", "server_time"):
        expect(key in body, "hello response lacks %s" % key)
    expect(body["printers_managed_by"] in ("server", "agent"), "printers_managed_by=%r" % body["printers_managed_by"])
    expect(ISO.match(str(body["server_time"])), "server_time not ISO 8601 UTC: %r" % body["server_time"])
    expect(float(body["lease_s"]) > 0 and float(body["poll_interval_s"]) > 0, "lease_s/poll_interval_s must be > 0")
    caps = body["server_capabilities"]
    expect("long_poll_max_s" in caps and "content_url" in caps, "server_capabilities incomplete: %s" % caps)
    if body["printers_managed_by"] == "server":
        ids = [p.get("printer_id") for p in body["printers"]]
        expect(ctx.printer_id in ids, "printer %s not in hello.printers %s" % (ctx.printer_id, ids))
        for p in body["printers"]:
            for key in ("printer_id", "role", "transport", "language", "dpi", "media"):
                expect(key in p, "printer %s lacks %s" % (p.get("printer_id"), key))
    ctx.hello_payload = body
    if abs(float(body["lease_s"]) - ctx.lease_s) > 0.01:
        return "ok; note: hello.lease_s=%s differs from --lease-s %s (using hello)" % (body["lease_s"], ctx.lease_s)
    return "ok, mode=%s, lease_s=%s" % (body["printers_managed_by"], body["lease_s"])


@check("hello_bad_request")
def c_hello_bad(ctx: Ctx) -> str:
    """hello without agent/capabilities -> 400 bad_request."""
    status, _, body = ctx.pap.call("POST", "/hello", {"agent": {"version": "x"}})
    expect(status == 400 and body.get("error") == "bad_request", "HTTP %d %s" % (status, body))
    return "400 bad_request"


@check("claim_empty")
def c_claim_empty(ctx: Ctx) -> str:
    """claim with nothing queued -> 204 with Retry-After."""
    ctx.drain()
    status, hdrs, body = ctx.pap.claim([ctx.printer_id])
    if status == 200:
        raise Skip("queue for %s is not empty (job %s); cannot test 204" % (ctx.printer_id, body.get("job_id")))
    expect(status == 204, "HTTP %d, expected 204" % status)
    ra = hdrs.get("Retry-After")
    expect(ra is not None and ra.isdigit() and int(ra) >= 1, "Retry-After missing or not a positive integer: %r" % ra)
    return "204, Retry-After: %s" % ra


@check("claim_bad_request")
def c_claim_bad(ctx: Ctx) -> str:
    """claim with empty printer_ids -> 400 bad_request."""
    status, _, body = ctx.pap.call("POST", "/jobs/claim", {"printer_ids": []})
    expect(status == 400 and body.get("error") == "bad_request", "HTTP %d %s" % (status, body))
    status, _, body = ctx.pap.call("POST", "/jobs/claim", {"printer_ids": ["no-such-printer-xyz"]})
    expect(status == 400, "unknown printer only: HTTP %d, expected 400" % status)
    return "400 for empty and for unknown-only printer_ids"


@check("ack_unknown_job")
def c_ack_unknown(ctx: Ctx) -> str:
    """ack on an unknown job -> 404 not_found."""
    status, _, body = ctx.pap.ack("pj_does_not_exist", "lt_x", "printed")
    expect(status == 404 and body.get("error") == "not_found", "HTTP %d %s" % (status, body))
    return "404 not_found"


@check("release")
def c_release(ctx: Ctx) -> str:
    """GET /release -> 204 or a release object."""
    status, _, body = ctx.pap.call("GET", "/release?os=linux&arch=amd64&current=0.0.0")
    if status == 204:
        return "204 (no update channel)"
    expect(status == 200, "HTTP %d" % status)
    for key in ("version", "url", "sha256"):
        expect(key in body, "release lacks %s" % key)
    return "200 version %s" % body["version"]


@check("job_shape", needs_jobs=True)
def c_job_shape(ctx: Ctx) -> str:
    """A claimed job has every required field and consistent content/sha256/times."""
    ctx.drain()
    job_id = ctx.jobs.new_job()
    job = ctx.claim_job(job_id)
    for key in ("job_id", "printer_id", "format", "content_sha256", "lease_token", "lease_until",
                "expires_at", "expires_in_s", "attempt", "redelivered", "server_time"):
        expect(key in job, "job lacks %s" % key)
    expect(("content_base64" in job) != ("content_url" in job), "exactly one of content_base64/content_url")
    expect(job["printer_id"] == ctx.printer_id, "printer_id %r" % job["printer_id"])
    expect(job["attempt"] == 1 and job["redelivered"] is False, "attempt/redelivered on first lease: %s/%s"
           % (job["attempt"], job["redelivered"]))
    expect(float(job["expires_in_s"]) > 0, "expires_in_s must be > 0 on a fresh job")
    expect(ISO.match(job["lease_until"]) and ISO.match(job["expires_at"]), "lease_until/expires_at not ISO 8601")
    if "content_base64" in job:
        content = base64.b64decode(job["content_base64"])
        expect(hashlib.sha256(content).hexdigest() == job["content_sha256"], "content_sha256 does not match content")
        expect(content == ZPL, "content differs from what was enqueued")
    ctx.pap.ack(job_id, job["lease_token"], "printed", detail="conformance")
    return "all fields present, sha256 consistent"


@check("redelivery_before_new", needs_jobs=True)
def c_redelivery(ctx: Ctx) -> str:
    """claim repeated without ack returns the same job: redelivered, same token, same attempt."""
    ctx.drain()
    j1 = ctx.jobs.new_job()
    j2 = ctx.jobs.new_job()
    first = ctx.claim_job(j1)
    status, _, again = ctx.pap.claim([ctx.printer_id])
    expect(status == 200, "second claim HTTP %d" % status)
    expect(again["job_id"] == j1, "second claim returned %s, expected the leased job %s (queued %s must wait)"
           % (again["job_id"], j1, j2))
    expect(again["redelivered"] is True, "redelivered must be true")
    expect(again["lease_token"] == first["lease_token"], "lease_token must stay the same on redelivery")
    expect(again["attempt"] == first["attempt"], "attempt must not change on redelivery")
    ctx.pap.ack(j1, first["lease_token"], "printed")
    nxt = ctx.claim_job(j2)
    ctx.pap.ack(j2, nxt["lease_token"], "printed")
    return "leased job redelivered first, new job after the ack"


@check("ack_printed_idempotent", needs_jobs=True)
def c_ack_idem(ctx: Ctx) -> str:
    """ack printed -> 200 printed; same ack again -> 200, no change; job no longer claimable."""
    ctx.drain()
    job_id = ctx.jobs.new_job()
    job = ctx.claim_job(job_id)
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "printed", detail="socket x, 1 B")
    expect(status == 200 and body.get("status") == "printed", "first ack: HTTP %d %s" % (status, body))
    expect(body.get("late") is False, "late should be false: %s" % body)
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "printed", detail="socket x, 1 B")
    expect(status == 200 and body.get("status") == "printed", "repeated ack: HTTP %d %s" % (status, body))
    status, _, again = ctx.pap.claim([ctx.printer_id])
    expect(status == 204 or again.get("job_id") != job_id, "printed job was handed out again")
    return "200/200, job closed"


@check("ack_conflict_409", needs_jobs=True)
def c_409(ctx: Ctx) -> str:
    """ack with a different final outcome on a closed job -> 409 conflict with status."""
    ctx.drain()
    job_id = ctx.jobs.new_job()
    job = ctx.claim_job(job_id)
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "failed", detail="boom")
    expect(status == 200 and body.get("status") == "failed", "ack failed: HTTP %d %s" % (status, body))
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "printed")
    expect(status == 409 and body.get("error") == "conflict", "HTTP %d %s, expected 409 conflict" % (status, body))
    expect(body.get("status") == "failed", "409 body must carry status=failed: %s" % body)
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "rejected", reason="expired")
    expect(status == 200 and body.get("status") == "failed", "rejected on failed should be idempotent 200: %d %s"
           % (status, body))
    return "409 conflict, status=failed; rejected==failed idempotent"


@check("bad_lease_token", needs_jobs=True)
def c_bad_token(ctx: Ctx) -> str:
    """ack with a token never issued for the job -> 403 bad_lease_token."""
    ctx.drain()
    job_id = ctx.jobs.new_job()
    job = ctx.claim_job(job_id)
    status, _, body = ctx.pap.ack(job_id, "lt_never_issued", "printed")
    expect(status == 403 and body.get("error") == "bad_lease_token", "HTTP %d %s" % (status, body))
    status, _, body = ctx.pap.ack(job_id, None, "printed")
    expect(status in (400, 403), "missing token: HTTP %d" % status)
    ctx.pap.ack(job_id, job["lease_token"], "printed")
    return "403 bad_lease_token"


@check("rejected_maps_to_failed", needs_jobs=True)
def c_rejected(ctx: Ctx) -> str:
    """ack rejected with a reason -> status failed; rejected without reason -> 400."""
    ctx.drain()
    job_id = ctx.jobs.new_job()
    job = ctx.claim_job(job_id)
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "rejected")
    expect(status == 400, "rejected without reason: HTTP %d, expected 400" % status)
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "rejected", reason="unsupported_route", detail="pdf on epl")
    expect(status == 200 and body.get("status") == "failed", "HTTP %d %s" % (status, body))
    return "failed with reason"


@check("failed_reason_code", needs_jobs=True)
def c_failed_reason(ctx: Ctx) -> str:
    """failed/uncertain acks may carry a machine `reason` code (6.5); a malformed code -> 400."""
    ctx.drain()
    job_id = ctx.jobs.new_job()
    job = ctx.claim_job(job_id)
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "failed", reason="Not A Code!", detail="x")
    expect(status == 400, "malformed reason: HTTP %d, expected 400" % status)
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "failed", reason="printer_unreachable",
                                  detail="socket 10.0.0.9:9100: Connection refused")
    expect(status == 200 and body.get("status") == "failed", "HTTP %d %s" % (status, body))
    if ctx.jobs.has_admin:
        view = ctx.jobs.admin("GET", "/jobs/" + job_id)
        expect(view.get("reason") == "printer_unreachable", "stored reason: %r" % view.get("reason"))
    return "reason printer_unreachable accepted on failed, malformed code 400"


@check("discovery", needs_jobs=True)
def c_discovery(ctx: Ctx) -> str:
    """Printer discovery (14): the request rides on hello and claim, the result closes it; repeats are harmless."""
    if not ctx.jobs.has_admin:
        raise Skip("needs the reference admin API")
    caps = {"transports": ["socket"], "formats": ["zpl", "raw"], "discovery": True}
    status, _, hello = ctx.pap.hello(capabilities=caps)
    expect(status == 200, "hello: HTTP %d" % status)
    if not (hello.get("server_capabilities") or {}).get("discovery"):
        raise Skip("server does not announce server_capabilities.discovery")
    expect("discover" not in hello, "hello carries `discover` although nobody asked")
    asked = ctx.jobs.admin("POST", "/agents/%s/discover" % ctx.jobs.agent_id)
    rid = asked["request_id"]
    status, _, hello = ctx.pap.hello(capabilities=caps)
    expect(hello.get("discover") == rid, "hello.discover %r, expected %r" % (hello.get("discover"), rid))
    ctx.drain()
    status, hdrs, _ = ctx.pap.claim([ctx.printer_id])
    expect(status == 204 and {k.lower(): v for k, v in hdrs.items()}.get("x-pap-discover") == rid,
           "claim 204 must carry X-PAP-Discover: %s (HTTP %d %s)" % (rid, status, hdrs))
    for bad in ({"discovered_printers": "nope"}, {"discovered_printers": [{"ports": [9100]}]},
                {"discovered_printers": [], "request_id": 5}):
        status, _, _ = ctx.pap.call("POST", "/discovery", bad)
        expect(status == 400, "malformed result %s: HTTP %d, expected 400" % (bad, status))
    result = {"request_id": rid, "agent_time": "2026-01-01T00:00:00Z", "started_at": "2026-01-01T00:00:00Z",
              "finished_at": "2026-01-01T00:00:03Z", "duration_s": 3.0, "subnets": ["10.0.0.0/24"], "hosts_probed": 253,
              "truncated": False, "error": None,
              "discovered_printers": [{"address": "10.0.0.15", "ports": [9100], "model": "Zebra ZD421", "vendor": "Zebra",
                                       "hostname": None, "sources": ["tcp", "snmp"], "mac": None,
                                       "already_configured": True}]}
    status, _, body = ctx.pap.call("POST", "/discovery", result)
    expect(status == 200 and body.get("matched") is True, "result: HTTP %d %s" % (status, body))
    status, _, body = ctx.pap.call("POST", "/discovery", result)
    expect(status == 200 and body.get("matched") is False, "repeat: HTTP %d %s (idempotent, nothing pending any more)" % (status, body))
    status, _, hello = ctx.pap.hello(capabilities=caps)
    expect("discover" not in hello, "hello still asks after the result arrived")
    status, hdrs, _ = ctx.pap.claim([ctx.printer_id])
    expect(status != 204 or "x-pap-discover" not in {k.lower() for k in hdrs}, "claim still asks after the result")
    stored = ctx.jobs.admin("GET", "/agents/%s/discovered" % ctx.jobs.agent_id)
    expect(stored["pending_request_id"] is None and stored["result"]["discovered_printers"][0]["address"] == "10.0.0.15",
           "admin view: %s" % json.dumps(stored)[:200])
    return "request %s: hello + claim 204 carry it, result 200 matched, repeat harmless, 3 malformed results 400" % rid


@check("lease_expiry_requeue", needs_jobs=True)
def c_lease_expiry(ctx: Ctx) -> str:
    """After lease_s without ack the job is leased again: attempt 2, new token; the old token still acks (late)."""
    lease = float((ctx.hello_payload or {}).get("lease_s", ctx.lease_s))
    if lease > 15:
        raise Skip("lease_s=%s too long for an automated check (use an agent with lease_s <= 15)" % lease)
    ctx.drain()
    job_id = ctx.jobs.new_job()
    first = ctx.claim_job(job_id)
    time.sleep(lease + 1.0)
    second = ctx.claim_job(job_id)
    expect(second["redelivered"] is False, "after lease expiry the job is a new lease, not a redelivery")
    expect(second["attempt"] == 2, "attempt=%s, expected 2" % second["attempt"])
    expect(second["lease_token"] != first["lease_token"], "a new lease needs a new token")
    status, _, body = ctx.pap.ack(job_id, first["lease_token"], "printed", detail="late from first lease")
    expect(status == 200 and body.get("status") == "printed", "old token ack: HTTP %d %s" % (status, body))
    expect(body.get("late") is True, "ack with a superseded token must be flagged late")
    status, _, body = ctx.pap.ack(job_id, second["lease_token"], "printed")
    expect(status == 200 and body.get("status") == "printed", "same outcome from second lease: HTTP %d %s" % (status, body))
    return "requeued after %.0fs, attempt 2, old token accepted late" % lease


@check("spooled_never_requeues", needs_jobs=True)
def c_spooled(ctx: Ctx) -> str:
    """spooled is neither requeued nor redelivered (does not block newer jobs); final printed closes it."""
    lease = float((ctx.hello_payload or {}).get("lease_s", ctx.lease_s))
    if lease > 15:
        raise Skip("lease_s=%s too long for an automated check" % lease)
    ctx.drain()
    job_id = ctx.jobs.new_job()
    job = ctx.claim_job(job_id)
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "spooled", spool_job_id="42")
    expect(status == 200 and body.get("status") == "spooled", "ack spooled: HTTP %d %s" % (status, body))
    time.sleep(lease + 1.0)
    status, _, nothing = ctx.pap.claim([ctx.printer_id])
    expect(status == 204, "spooled job must not be handed out again (claim: HTTP %d %s)" % (status, nothing))
    newer_id = ctx.jobs.new_job()
    status, _, newer = ctx.pap.claim([ctx.printer_id])
    expect(status == 200 and newer.get("job_id") == newer_id and newer.get("redelivered") is False,
           "a newer job must not be blocked by a spooled one (claim: HTTP %d %s)" % (status, newer))
    ctx.pap.ack(newer_id, newer["lease_token"], "printed")
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "printed", detail="spooler done")
    expect(status == 200 and body.get("status") == "printed", "final ack: HTTP %d %s" % (status, body))
    return "spooled kept across lease expiry, not redelivered, newer job served, closed by printed"


@check("lease_extend", needs_jobs=True)
def c_lease_extend(ctx: Ctx) -> str:
    """extend keeps a leased job out of the queue past lease_s; unknown token 403, closed job 409 (if announced)."""
    lease = float((ctx.hello_payload or {}).get("lease_s", ctx.lease_s))
    caps = (ctx.hello_payload or {}).get("server_capabilities") or {}
    if not caps.get("lease_extend"):
        raise Skip("server does not announce server_capabilities.lease_extend")
    if lease > 15:
        raise Skip("lease_s=%s too long for an automated check" % lease)
    ctx.drain()
    job_id = ctx.jobs.new_job()
    job = ctx.claim_job(job_id)
    for _ in range(3):
        time.sleep(lease * 0.6)
        status, _, body = ctx.pap.call("POST", "/jobs/%s/extend" % job_id, {"lease_token": job["lease_token"]})
        expect(status == 200 and ISO.match(str(body.get("lease_until"))), "extend: HTTP %d %s" % (status, body))
    status, _, body = ctx.pap.claim([ctx.printer_id])
    expect(status in (200, 204) and not (status == 200 and body.get("job_id") == job_id and not body.get("redelivered")),
           "an extended lease must not turn into a new lease (claim: HTTP %d %s)" % (status, body))
    status, _, body = ctx.pap.call("POST", "/jobs/%s/extend" % job_id, {"lease_token": "lt_neverissued"})
    expect(status == 403 and body.get("error") == "bad_lease_token", "bad token: HTTP %d %s" % (status, body))
    ctx.pap.ack(job_id, job["lease_token"], "printed")
    status, _, body = ctx.pap.call("POST", "/jobs/%s/extend" % job_id, {"lease_token": job["lease_token"]})
    expect(status == 409 and body.get("error") == "conflict" and body.get("status") == "printed",
           "extend after the final ack: HTTP %d %s" % (status, body))
    status, _, body = ctx.pap.call("POST", "/jobs/nosuchjob/extend", {"lease_token": job["lease_token"]})
    expect(status == 404, "unknown job: HTTP %d" % status)
    return "3 extends over %.0fs of a %.0fs lease, 403 bad token, 409 after ack, 404 unknown" % (lease * 1.8, lease)


@check("late_ack_after_expiry", needs_jobs=True)
def c_late_after_expiry(ctx: Ctx) -> str:
    """printed ack on a job past expires_at is recorded as printed, late:true (needs admin API)."""
    if not ctx.jobs.has_admin:
        raise Skip("needs the reference admin API to set expires_in_s")
    ctx.drain()
    job_id = ctx.jobs.new_job(expires_in_s=2)
    job = ctx.claim_job(job_id)
    time.sleep(2.5)
    status, _, body = ctx.pap.ack(job_id, job["lease_token"], "printed", detail="label came out")
    expect(status == 200 and body.get("status") == "printed", "HTTP %d %s" % (status, body))
    expect(body.get("late") is True, "late must be true after expires_at")
    return "printed, late"


@check("expired_not_handed_out", needs_jobs=True)
def c_expired_hidden(ctx: Ctx) -> str:
    """A queued job past expires_at is never returned by claim (needs admin API)."""
    if not ctx.jobs.has_admin:
        raise Skip("needs the reference admin API to set expires_in_s")
    ctx.drain()
    job_id = ctx.jobs.new_job(expires_in_s=1)
    time.sleep(1.5)
    status, _, body = ctx.pap.claim([ctx.printer_id])
    expect(status == 204 or body.get("job_id") != job_id, "expired job %s was handed out" % job_id)
    return "expired job hidden"


@check("content_url", needs_jobs=True)
def c_content_url(ctx: Ctx) -> str:
    """content_url delivers the bytes to the key holder only, on the server's origin (needs admin API)."""
    if not ctx.jobs.has_admin:
        raise Skip("needs the reference admin API to choose delivery=url")
    ctx.drain()
    job_id = ctx.jobs.new_job(delivery="url")
    job = ctx.claim_job(job_id)
    expect("content_url" in job and "content_base64" not in job, "job should carry content_url only")
    origin = "/".join(ctx.pap.url.split("/", 3)[:3])
    expect(job["content_url"].startswith(origin + "/"), "content_url %s not on %s" % (job["content_url"], origin))
    status, hdrs, raw = ctx.pap.call("GET", job["content_url"], absolute=True)
    expect(status == 200 and isinstance(raw, bytes), "GET content: HTTP %d" % status)
    expect(hashlib.sha256(raw).hexdigest() == job["content_sha256"], "content bytes do not match content_sha256")
    status, _, _ = ctx.pap.call("GET", job["content_url"], absolute=True, key=None)
    expect(status == 401, "content without key: HTTP %d, expected 401" % status)
    ctx.pap.ack(job_id, job["lease_token"], "printed")
    status, _, _ = ctx.pap.call("GET", job["content_url"], absolute=True)
    expect(status == 404, "content after final ack: HTTP %d, expected 404" % status)
    return "bytes match, 401 without key, 404 after close"


@check("revoked", needs_jobs=True)
def c_revoked(ctx: Ctx) -> str:
    """Revoked key -> 401 {"error":"revoked"}; restored key works again (needs admin API)."""
    if not ctx.jobs.has_admin:
        raise Skip("needs the reference admin API to revoke the key")
    ctx.jobs.admin("PATCH", "/agents/%s" % ctx.jobs.agent_id, {"revoked": True})
    try:
        status, _, body = ctx.pap.hello()
        expect(status == 401 and body.get("error") == "revoked", "HTTP %d %s" % (status, body))
    finally:
        ctx.jobs.admin("PATCH", "/agents/%s" % ctx.jobs.agent_id, {"revoked": False})
    status, _, _ = ctx.pap.hello()
    expect(status == 200, "after restore: HTTP %d" % status)
    return "401 revoked, then 200"


@check("server_time_everywhere")
def c_server_time(ctx: Ctx) -> str:
    """Every JSON response seen so far carried an ISO 8601 UTC server_time."""
    seen = [r for r in ctx.pap.responses if r["json"] is not None and r["status"] != 204]
    missing = [(r["method"], r["path"], r["status"]) for r in seen
               if not ISO.match(str(r["json"].get("server_time", "")))]
    expect(not missing, "responses without server_time: %s" % missing[:5])
    return "%d JSON responses checked" % len(seen)


# ---------------------------------------------------------------------------

def main() -> int:
    ap = argparse.ArgumentParser(description="PAP/1 server conformance")
    ap.add_argument("--url", help="PAP base URL (default: derived from --admin-url for the test admin API server)")
    ap.add_argument("--key", help="agent key pa1_...; with --admin-url an agent is created when omitted")
    ap.add_argument("--printer-id", default="p1")
    ap.add_argument("--lease-s", type=float, default=3, help="lease_s configured for the agent (default 3)")
    ap.add_argument("--admin-url", help="test admin API, e.g. http://127.0.0.1:8090/admin")
    ap.add_argument("--enqueue-cmd", help="shell template creating a job: {printer_id} {file}; prints job_id")
    ap.add_argument("--only", help="comma-separated check names")
    ap.add_argument("--json", help="write results to this file")
    ap.add_argument("--list", action="store_true")
    args = ap.parse_args()
    if args.list:
        for c in CHECKS:
            print("%-26s %s%s" % (c["name"], "(jobs) " if c["needs_jobs"] else "", c["doc"]))
        return 0

    agent_id = None
    url, key = args.url, args.key
    if args.admin_url:
        admin = JobSource(args.admin_url, None, None, args.printer_id)
        if not url:
            url = admin.admin("GET", "/health")["pap_base"]
        if key:
            agents = admin.admin("GET", "/agents")["agents"]
            match = [a for a in agents if a["key_id"] == key.split("_")[1]]
            if not match:
                ap.error("--key does not belong to an agent on this server")
            agent_id = match[0]["agent_id"]
        else:
            created = admin.admin("POST", "/agents", {
                "name": "conformance", "lease_s": args.lease_s, "poll_interval_s": 1,
                "printers": [{"printer_id": args.printer_id, "role": "label", "transport": "socket",
                              "address": "127.0.0.1:9100", "language": "zpl", "dpi": 203, "media": "100x150mm"}]})
            key, agent_id = created["key"], created["agent_id"]
            print("created agent %s through the test admin API" % agent_id)
    if not url or not key:
        ap.error("--url and --key are required without --admin-url")

    pap = Pap(url, key)
    jobs = JobSource(args.admin_url, args.enqueue_cmd, agent_id, args.printer_id)
    ctx = Ctx(pap, jobs, args.printer_id, args.lease_s)
    selected = CHECKS if not args.only else [c for c in CHECKS if c["name"] in args.only.split(",")]

    print("server: %s\n" % url)
    print("%-26s %-5s %6s  %s" % ("check", "result", "secs", "note"))
    print("-" * 96)
    results = []
    for c in selected:
        started = time.time()
        result, note = "PASS", ""
        try:
            if c["needs_jobs"] and not jobs.available:
                raise Skip("no --admin-url / --enqueue-cmd")
            note = c["fn"](ctx) or ""
        except Skip as exc:
            result, note = "SKIP", str(exc)
        except Fail as exc:
            result, note = "FAIL", str(exc)
        except Exception as exc:  # noqa: BLE001
            result, note = "FAIL", "%s: %s" % (type(exc).__name__, exc)
        secs = time.time() - started
        results.append({"name": c["name"], "result": result, "seconds": round(secs, 2), "note": note})
        print("%-26s %-5s %6.1f  %s" % (c["name"], result, secs, note[:70]))
    failed = [r for r in results if r["result"] == "FAIL"]
    print("-" * 96)
    print("%d passed, %d failed, %d skipped" % (sum(r["result"] == "PASS" for r in results), len(failed),
                                                 sum(r["result"] == "SKIP" for r in results)))
    for r in failed:
        print("  FAIL %s: %s" % (r["name"], r["note"]))
    if args.json:
        with open(args.json, "w", encoding="utf-8") as fh:
            json.dump({"server": url, "results": results}, fh, indent=1)
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
