# PAP/1 — Print Agent Protocol, version 1

PAP/1 is a small HTTPS/JSON protocol between a **print agent** (a service running
next to printers, inside a LAN) and a **server** (any system that produces print
jobs: a warehouse system, a shop backend, a label service). The agent only ever
makes outbound requests; it never listens. The server never reaches into the
LAN. Jobs are delivered by polling with a lease, and the protocol is designed so
that a lost request, a lost response or a crashed agent never prints a label
twice without someone knowing.

This document is normative. "MUST", "SHOULD" and "MAY" are used as in RFC 2119.
A server and an agent written independently from this document alone are
expected to interoperate; the server conformance suite (`tests/conformance/` in
the public `kamilmat/printdock-php` repository) is the tie-breaker when the text is unclear.

Contents

1. Transport, headers, versioning
2. Authentication
3. Data types and time
4. Printers and configuration (`hello`)
5. Claiming jobs (`claim`) and the redelivery rule
6. Reporting results (`ack`)
7. Fetching job content (`content`)
8. Updates (`release`)
9. Job formats and options
10. Server-side job states
11. Agent requirements (idempotency, journal, single instance)
12. Error catalogue
13. Complete examples
14. Network printer discovery (`discover`, `POST /discovery`)

---

## 1. Transport, headers, versioning

- HTTPS in production. Plain HTTP is allowed only for local testing and the
  agent MUST refuse `http://` unless explicitly configured to allow it.
- Bodies are JSON, UTF-8, `Content-Type: application/json`. Empty-body
  responses (`204`) carry no content type.
- **Base URL.** The agent is configured with a `server_url`, for example
  `https://wms.example.com/api/printing/agent/v1`. All paths in this document
  are relative to it (the base has no trailing slash; the agent appends
  `/hello`, `/jobs/claim`, …).
- **Request headers the agent MUST send on every call:**

  | Header | Value |
  |---|---|
  | `Authorization` | `Bearer pa1_…` (section 2) |
  | `X-PAP-Version` | `1` (the protocol major version) |
  | `User-Agent` | `printdock/<version> (<os>/<arch>)`, e.g. `printdock/1.0.3 (windows/amd64)` |
  | `Content-Type` | `application/json` on requests with a body |

- **Response headers the server MUST send on every PAP response, including
  errors:** `X-PAP-Version: 1`.
- **Version check.** The agent compares the major version in the response
  header with its own. On a mismatch the agent MUST stop processing jobs,
  log the mismatch and exit (or stop polling, if it is a service that cannot
  exit) — it MUST NOT guess. A missing `X-PAP-Version` response header is
  treated as a mismatch. The server MUST reject requests whose
  `X-PAP-Version` major is not `1` with `400 {"error":"unsupported_version"}`
  and MUST reject requests without the header the same way.
- **Compatibility within a major version:** only fields are added, never
  removed or changed in meaning. Both sides MUST ignore unknown JSON fields.
  New abilities are announced in `capabilities` / `server_capabilities`,
  not with a new version number.
- **Keep-alive** is recommended (one TLS connection reused across polls).
- **Retries.** The agent retries on network errors and on `5xx` with
  exponential backoff (initial 1 s, factor 2, capped — 30 s in the PrintDock
  agent). The agent MUST NOT retry `4xx` responses, except `429`,
  which it retries after the `Retry-After` header (seconds).
- **Idempotency of calls.** `hello`, `ack`, `content` and `release` are
  idempotent: repeating the same call yields the same result. `claim` is not
  idempotent by itself; section 5 explains how the redelivery rule and the
  agent journal compensate.

## 2. Authentication

- Every agent has an **agent key** of the form

  ```
  pa1_<key_id>_<secret>
  ```

  `pa1_` is a literal prefix and part of the protocol; `<key_id>` is 8
  characters `[a-z0-9]`; `<secret>` is 32 random bytes encoded as base64url
  without padding (43 characters). Example:
  `pa1_7f3k2m9q_Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWo`.
- The key is sent as `Authorization: Bearer <key>`. Servers that accept
  other bearer tokens on the same endpoint family (JWT etc.) recognise agent
  keys by the `pa1_` prefix.
- The server stores only a hash of the secret (SHA-256 is sufficient) and
  looks the record up by `key_id`.
- A request with no key, an unknown key or a wrong secret is answered
  `401 {"error":"unauthorized"}`. A request with a key that has been
  **revoked** is answered `401 {"error":"revoked"}`. On `revoked` the agent
  MUST stop claiming jobs, log the fact, and retry `hello` periodically
  (default every 60 s) so that a re-enabled key starts working without a
  restart. On `unauthorized` the agent behaves the same way.
- Keys are issued by the server out of band (shown once in the server UI and
  typed into the agent's installation). An optional `POST /enroll` exchange
  (one-time code → key) is reserved for a later version; servers announce it
  with `server_capabilities.enroll`.

## 3. Data types and time

- Timestamps are ISO 8601 in UTC with a `Z` suffix and at least second
  precision: `2026-10-30T13:41:34Z`. Fractional seconds are allowed.
- **Every PAP JSON response body carries `server_time`.** The agent computes
  `offset = server_time − local_time_at_receipt` and applies that offset
  whenever it compares `lease_until` or `expires_at` with its own clock.
  Clocks on PCs next to printers are frequently minutes off; the protocol
  does not trust them.
- Durations are numbers of seconds (`*_s`). Servers MAY send fractional
  values; agents MUST accept them.
- Identifiers (`agent_id`, `printer_id`, `job_id`, `lease_token`) are opaque
  strings up to 128 characters. Agents MUST NOT parse them.
- Binary content is base64 (standard alphabet, with padding) in
  `content_base64`. `content_sha256` is the lowercase hex SHA-256 of the
  **decoded** bytes.

## 4. Printers and configuration — `POST /hello`

`hello` is registration, heartbeat and configuration download in one call.
The agent sends it at start-up, then every `hello_interval_s`, and
additionally whenever its list of system printers changes.

### 4.1 Request

```json
{
  "agent": {"version": "1.0.3", "os": "windows", "arch": "amd64", "hostname": "PACKING-1", "state": "active"},
  "capabilities": {
    "transports": ["socket", "spooler"],
    "formats": ["zpl", "epl", "raw", "pdf"],
    "raster_languages": ["zpl"],
    "pdf_driver": true,
    "long_poll": true
  },
  "seen_printers": [
    {"system_name": "Godex G500", "driver": "Godex G500", "port": "USB001"}
  ],
  "printers": [],
  "config_version": "2026-10-28T09:12:00Z",
  "last_error": null,
  "spooled_jobs": 0
}
```

| Field | Required | Meaning |
|---|---|---|
| `agent.version` | yes | agent software version string |
| `agent.os` | yes | `windows`, `linux`, `darwin` (lower case, Go `GOOS` names) |
| `agent.arch` | yes | `amd64`, `arm64`, `386`, `arm` |
| `agent.hostname` | yes | machine name, informational |
| `agent.state` | no | `active` (default when absent) or `paused`: the operator stopped printing on this station (local control in the PrintDock agent). A paused agent keeps sending `hello` and acks but sends no `claim`; the server SHOULD show the state and MUST NOT treat the missing claims as a fault. Added in PAP/1 without a version change (section 1: fields are only added) |
| `capabilities.transports` | yes | subset of `socket`, `spooler`, `cups` (section 9) |
| `capabilities.formats` | yes | subset of `zpl`, `epl`, `raw`, `pdf`, `png` |
| `capabilities.raster_languages` | no | printer languages the agent can rasterise `pdf` into without a driver (today: `zpl`); default `[]` |
| `capabilities.pdf_driver` | no | `true` if the agent can print `pdf` through a system driver (`spooler`/`cups`); default `false` |
| `capabilities.long_poll` | no | `true` if the agent is willing to send `wait_s` on `claim`; default `false` |
| `capabilities.discovery` | no | `true` if the agent can look for printers in its local network on request (section 14); default `false`. The server asks only agents that announce it |
| `capabilities.layout` | no | list of PDF layout features beyond the first PAP/1 options that the agent implements (section 9.2): `shrink` (`fit: shrink`) and `auto_rotate` (`rotate: auto`); default `[]`. A server MUST NOT send `fit: shrink` or `rotate: auto` to an agent that did not list the matching feature; it sends `page` / `0` instead. Added in PAP/1 without a version change |
| `seen_printers[]` | no | printers the operating system shows: `system_name` (required), `driver`, `port`; informational — the server UI uses it to offer choices. Optional `unsupported` (a code) and `unsupported_reason` (text) mark a queue the agent cannot print to unattended — e.g. `ui_queue`: the port or driver needs a dialog (`PORTPROMPT:`, `FILE:`, Microsoft Print to PDF) that a service cannot answer. The server SHOULD NOT offer such queues; jobs sent to one end `failed` at once. Added without a version change |
| `printers[]` | only in `agent` mode | the agent's own printer configuration, **without addresses** (`printer_id`, `role`, `language`, `dpi`, `media`, optionally `transport`); see 4.3 |
| `config_version` | no | the `config_version` the agent last received, or `null` |
| `last_error` | no | last error message, trimmed to 500 characters, or `null` |
| `spooled_jobs` | no | number of jobs currently sitting in a system spooler, default `0` |

### 4.2 Response `200`

```json
{
  "agent_id": "ag_7f3k2m",
  "agent_name": "Packing 1",
  "poll_interval_s": 2,
  "hello_interval_s": 60,
  "lease_s": 60,
  "spool_timeout_s": 120,
  "printers_managed_by": "server",
  "config_version": "2026-10-30T08:00:00Z",
  "printers": [
    {"printer_id": "pr_label1", "role": "label", "transport": "socket", "address": "10.0.0.15:9100",
     "language": "zpl", "dpi": 203, "media": "100x150mm"},
    {"printer_id": "pr_godex", "role": "label", "transport": "spooler", "system_name": "Godex G500",
     "language": "driver", "dpi": 203, "media": "100x150mm"}
  ],
  "server_capabilities": {"long_poll_max_s": 0, "content_url": true, "enroll": false, "lease_extend": true, "discovery": true},
  "server_time": "2026-10-30T13:41:30Z"
}
```

| Field | Meaning |
|---|---|
| `agent_id`, `agent_name` | server-side identity and display name of this agent (station) |
| `poll_interval_s` | how often to `claim` when the last claim returned nothing; the agent MUST honour it (it MAY poll slower, never faster) |
| `hello_interval_s` | how often to repeat `hello` |
| `lease_s` | how long a claimed job stays leased to the agent without an ack (section 5) |
| `spool_timeout_s` | when a system queue makes **no progress** for this many seconds (none of the agent's jobs in it finished, started or printed a page), the agent cancels its jobs in that queue and acks them `failed`; a long but moving queue is never cancelled |
| `printers_managed_by` | `server` or `agent` (4.3) |
| `config_version` | opaque string; changes whenever the station configuration changes. The agent compares it with the last one it saw and reloads printers when it differs |
| `printers[]` | the station's configured printers (4.3). In `agent` mode the server SHOULD return `[]` and the agent MUST ignore it |
| `server_capabilities.long_poll_max_s` | `0` = no long polling; otherwise the maximum `wait_s` the server honours |
| `server_capabilities.content_url` | `true` if the server may deliver content by URL (section 7); agents MUST support both delivery modes regardless |
| `server_capabilities.enroll` | reserved, `false` in PAP/1 |
| `server_capabilities.lease_extend` | `true` if the server implements `POST /jobs/{job_id}/extend` (5.6); default `false`. An agent calls `extend` only when this is `true` |
| `server_capabilities.discovery` | `true` if the server implements `POST /discovery` (section 14); default `false`. An agent sends a result only when this is `true` |
| `discover` | optional request id (string) present while an administrator's request for a printer scan is open (section 14). Absent otherwise |
| `server_time` | section 3 |

### 4.3 Printer objects and who owns the configuration

A printer object:

| Field | Required | Values |
|---|---|---|
| `printer_id` | yes | opaque id, unique within the agent |
| `role` | yes | free string a server may address jobs by, e.g. `label`, `a4`, `receipt` |
| `transport` | yes | `socket` — raw TCP (JetDirect/9100); `spooler` — Windows print spooler, RAW datatype; `cups` — CUPS queue (`lp`) |
| `address` | `socket` only | `host:port` |
| `system_name` | `spooler` only | Windows printer name |
| `queue` | `cups` only | CUPS queue name |
| `language` | yes | `zpl`, `epl`, `ezpl`, `escpos` — the printer takes that language raw; `driver` — the printer has a system driver and takes documents (PDF) through it |
| `dpi` | yes | integer, e.g. `203`, `300` |
| `media` | yes | label/page size as `<width>x<height>mm`, e.g. `100x150mm`, `210x297mm` |
| `name` | no | human-readable name |
| `fit`, `rotate` | no | the printer's defaults for PDF jobs that do not set these options (section 9.2). Server-side configuration: the server applies them when it creates a job and the agent MAY ignore them here; it always follows the job's `options`. Added without a version change |

**`printers_managed_by: "server"`** — the server is the source of truth. The
agent uses the `printers[]` from the latest `hello`, caches them on disk
(`config-cache`) and uses the cache when the server is unreachable at
start-up. The agent reports `seen_printers` so the server UI can offer them.

**`printers_managed_by: "agent"`** — the agent is the source of truth (local
`printers` file). The agent MUST include its configuration in **every**
`hello` as `printers[]`, **without addresses, system names or queues** (the
server does not need them): `printer_id`, `role`, `language`, `dpi`,
`media`, optionally `transport`. The server records them so that it knows the
`printer_id`s and roles before creating jobs. Printer ids are assigned by the
local file in this mode. Because the agent learns the mode only from the
`hello` response, an agent that has a local printers file SHOULD send
`printers[]` in every `hello` regardless of mode; a server in `server` mode
ignores the field.

In both modes a job always carries a concrete `printer_id` (section 5.2);
the agent never resolves roles.

## 5. Claiming jobs — `POST /jobs/claim`

### 5.1 Request

```json
{"printer_ids": ["pr_label1", "pr_godex"], "max": 1, "wait_s": 0}
```

| Field | Required | Meaning |
|---|---|---|
| `printer_ids` | yes, non-empty | the printers the agent is able to serve right now (configured and routable) |
| `max` | no | PAP/1 defines only `1`; servers return at most one job per call |
| `wait_s` | no | long polling: hold the request up to this many seconds waiting for a job. The agent MUST NOT send more than `server_capabilities.long_poll_max_s`; the server clamps anyway. Omit or `0` for an immediate answer |

The server ignores unknown printer ids. If no known id remains, the server
answers `400 {"error":"bad_request"}`.

### 5.2 Response `200` — a job

```json
{
  "job_id": "pj_01JBD6W8K2",
  "printer_id": "pr_godex",
  "printer_role": "label",
  "format": "pdf",
  "content_base64": "JVBERi0xLjQK...",
  "content_sha256": "3b1f0c…",
  "options": {"copies": 1, "fit": "page", "rotate": 0},
  "lease_token": "lt_9a8e…",
  "lease_until": "2026-10-30T13:42:34Z",
  "expires_at": "2026-10-30T13:56:00Z",
  "expires_in_s": 866,
  "attempt": 1,
  "redelivered": false,
  "title": "DPD 1234567890 (order 98765)",
  "title_sensitive": false,
  "reference": {"kind": "courier_label", "source_ref": "opaque-for-agent"},
  "server_time": "2026-10-30T13:41:34Z"
}
```

| Field | Always | Meaning |
|---|---|---|
| `job_id` | yes | job identifier |
| `printer_id` | yes | target printer; always one of the ids the agent sent |
| `printer_role` | no | informational |
| `format` | yes | `zpl`, `epl`, `raw`, `pdf` (section 9) |
| `content_base64` **or** `content_url` | exactly one | inline bytes (recommended up to 2 MB) or a URL on the same server (section 7). The agent MUST support both |
| `content_sha256` | yes | SHA-256 of the decoded bytes. The agent MUST verify it and ack `rejected`/`bad_content` on mismatch |
| `options` | `pdf` only | section 9.2 |
| `lease_token` | yes | identifies this lease; required in `ack` |
| `lease_until` | yes | end of the lease (server clock) |
| `expires_at` | yes | after this the job is pointless (server clock) |
| `expires_in_s` | yes | `expires_at − server_time`, computed by the server at response time; may be ≤ 0 only if a server misbehaves. A simple agent may use only this field and never touch clock offsets |
| `attempt` | yes | 1 for the first lease, +1 every time the job returns to the queue and is leased again |
| `redelivered` | yes | `true` when this response repeats a job already leased (not spooled) to this agent (5.4) |
| `title` | no | short description for logs and screens, no personal data |
| `title_sensitive` | no | if `true` the agent MUST NOT write `title` to logs |
| `reference` | no | opaque object for the server's own bookkeeping; the agent MUST NOT interpret it |
| `server_time` | yes | section 3 |
| `discover` | no | the open discovery request id, as in 4.2 (section 14) |

### 5.3 Response `204` — nothing to do

No body. The server SHOULD send `Retry-After: <seconds>` (integer) with the
recommended delay before the next claim (normally `poll_interval_s`; larger
when the server knows the next job is not ready yet). The agent waits at
least `poll_interval_s`, or `Retry-After` when present.

Two cases are not "nothing to do" for the same set of printers, and the
agent MAY claim again at once: after a `204` to a claim with `wait_s > 0`
that the server held for the whole `wait_s` (the hold already paced the
agent; a larger `Retry-After` is still honoured), and when a printer that
was left out of the last claim because it was busy has become free. An
agent long-polls only while nothing else needs its attention and never past
its next `hello`, so heartbeats stay on time.

A server MAY answer `429 rate_limited` with `Retry-After` (section 12) to a
claim that comes sooner than `max(0.5 s, poll_interval_s / 2)` after a `204`
it did not hold, for no printer beyond those of that claim; it MUST NOT limit
the two cases above, nor a claim right after a `200`. The agent treats such
a `429` like a `204`: it waits `Retry-After` (at least `poll_interval_s`).

While a discovery request is open the server adds the header
`X-PAP-Discover: <request id>` (section 14) to `204` answers, and the field
`discover` to `200` answers, so a scan starts within one poll instead of one
`hello_interval_s`.

### 5.4 Server rules for `claim` (normative)

1. **Sweep first.** Before selecting, the server applies lease expiry: every
   job in state `leased` whose `lease_until` has passed returns to `queued`
   (its `attempt` counter is kept; the next lease increments it). Jobs in
   state `spooled` never return to the queue (section 10). A job the server
   created as **at most once** does not return either: its expired lease
   closes it as `uncertain` (section 10).
2. **Redelivery before new work.** The server returns, oldest first, any job
   that is **already `leased` to this agent** on one of the requested
   printers. Such a response has `redelivered: true`, the **same**
   `lease_token`, the same `attempt`, and a refreshed `lease_until`
   (`server_time + lease_s`). This rule is what makes a lost `claim`
   response cost one poll instead of `lease_s`.
   **`spooled` jobs are never redelivered.** The agent learned the job (it
   acked `spooled`) and tracks it from its own journal until the final ack;
   redelivering it would block newer jobs for that printer for as long as
   the spooler holds it. A `spooled` job without a final ack ends as
   `uncertain` (section 10).
3. **New work.** Only when there is nothing to redeliver does the server take
   the oldest `queued` job for one of the requested printers whose
   `expires_at` has not passed and whose `not_before` (if any) has passed. It
   sets `leased`, generates a new `lease_token`, sets
   `lease_until = server_time + lease_s`, increments `attempt`.
4. Expired `queued` jobs are never handed out; the server reports them as
   `expired` on its own side.
5. With `wait_s > 0` the server MAY hold the request until a job becomes
   available or `min(wait_s, long_poll_max_s)` elapses, then answers as above.

### 5.5 Agent rules for a received job (normative)

In this order:

1. **Journal check.** If the agent's journal says this `job_id` was already
   `sent` (bytes handed to the printer or spooler), do **not** print again:
   ack the recorded outcome (`printed`, or `spooled` then follow-up) with the
   job's `lease_token`. This protects against redelivery after a lost ack or
   a crash.
2. **Expiry.** If `expires_in_s ≤ 0`, or `expires_at` (after clock offset)
   is in the past, ack `rejected` with `reason: "expired"` and do not print.
3. **Printer.** If `printer_id` is not a configured printer, ack `rejected`
   / `unknown_printer`.
4. **Route.** If `format` cannot be printed on that printer's transport and
   language (section 9.3), ack `rejected` / `unsupported_route`. A `pdf` job
   whose `options` hold a `fit` or `rotate` value the agent does not
   implement (9.2) is acked `rejected` / `bad_options` here, before anything
   is printed.
5. **Content.** Decode inline or fetch `content_url`; verify
   `content_sha256`; on mismatch ack `rejected` / `bad_content`.
6. Journal `claimed`; print; journal `sent`; ack; journal `acked`.

### 5.6 Extending a lease — `POST /jobs/{job_id}/extend`

A job may take longer to print than `lease_s` (a driver waiting for the paper, a slow queue). An agent that is
still working on a leased job calls `extend` roughly every `lease_s / 3`, so the lease never lapses under it and
the job is not handed out again — which would print it twice. Servers announce the call with
`server_capabilities.lease_extend: true`; agents MUST NOT call it otherwise.

Request: `{"lease_token": "lt_9a8e…"}`. Response `200`:

```json
{"lease_until": "2026-10-30T13:43:34Z", "server_time": "2026-10-30T13:42:34Z"}
```

Server rules (normative): job unknown or not this agent's → `404 not_found`; `lease_token` never issued for the
job → `403 bad_lease_token`; the job is not `leased` with **this** token (it was acked, cancelled, or its lease
already lapsed and it went back to `queued` or to another lease) → `409 conflict` with the current `status`;
otherwise `lease_until = server_time + lease_s` and `200`. `extend` is idempotent and never changes the job state
or `attempt`. The agent logs a `409`/`404`, stops extending that job, and still finishes it and acks as usual
(section 6.4 accepts a late ack). A network error or `5xx` is retried at the next interval.

## 6. Reporting results — `POST /jobs/{job_id}/ack`

### 6.1 Request

```json
{
  "lease_token": "lt_9a8e…",
  "outcome": "printed",
  "detail": "socket 10.0.0.15:9100, 1234 B, 0.004 s",
  "reason": null,
  "agent_time": "2026-10-30T13:41:36Z",
  "spool_job_id": null
}
```

| Field | Required | Meaning |
|---|---|---|
| `lease_token` | yes | the token from the claim that delivered the job |
| `outcome` | yes | `printed`, `spooled`, `failed`, `uncertain`, `rejected` (6.2) |
| `detail` | no | free text ≤ 1000 characters. For `failed`, the operating system / spooler / driver message **verbatim** |
| `reason` | required for `rejected`, SHOULD accompany `failed` and `uncertain` | a machine code, lower case (`^[a-z][a-z0-9_]{0,39}$`). `rejected` uses `expired`, `unsupported_route`, `unknown_printer`, `bad_content`, `bad_options`; `failed` and `uncertain` use the codes of 6.5. `printed` and `spooled` send `null`. `detail` stays the human/verbatim text; `reason` is for programs and for choosing a message |
| `agent_time` | no | agent clock at the time of the ack, for diagnostics |
| `spool_job_id` | `spooled` only | identifier of the job in the system spooler |

### 6.2 Outcomes

| `outcome` | Meaning | Resulting server state |
|---|---|---|
| `printed` | bytes were handed to the printer (socket) or the spooler reported completion | `printed` |
| `spooled` | the system spooler accepted the job; the printer has not finished yet. The agent will send a final `printed` or `failed` later with the same `lease_token` | `spooled` |
| `failed` | printing failed (connection refused, spooler/driver error, cancelled after `spool_timeout_s` without progress) | `failed` |
| `uncertain` | the agent does not know whether bytes reached the printer (crash while sending, process killed between send and journal) | `uncertain` — a human decides by reprinting |
| `rejected` | not printable at this station; `reason` says why | `failed` with `reason` recorded; never requeued automatically |

A raw socket (9100) does not confirm printing: `printed` means the bytes
left the agent, not that a label came out of a jammed or switched-off
printer. That is a property of the transport; agents MUST NOT pretend
otherwise and servers SHOULD label the outcome accordingly in their UI.

### 6.3 Response

`200`:

```json
{"status": "printed", "late": false, "server_time": "2026-10-30T13:41:36Z"}
```

`status` is the server-side state after the ack (section 10). `late` is
`true` when the ack arrived after `lease_until` or after `expires_at`. A job in
`spooled` is no longer under its lease (it is never redelivered, 5.4), so its
final ack is late only after `expires_at`, with a token of an earlier lease, or
when the server had already given up on it (`uncertain`, rule 6.4.3).

### 6.4 Server rules for `ack` (normative)

Let S be the job's current state and O the outcome in the request.

1. Unknown `job_id` → `404 {"error":"not_found"}`.
2. `lease_token` that was **never** issued for this job → `403
   {"error":"bad_lease_token"}`. Tokens of **earlier** leases of the same job
   remain acceptable (the agent may legitimately report late, after the lease
   expired and the job was leased again).
3. Final states are `printed`, `failed`, `uncertain`, `cancelled`.
   - S final and O maps to the same state (`rejected` maps to `failed`) →
     `200`, no change (idempotent repeat).
   - S = `uncertain` and O ∈ {`printed`, `failed`, `rejected`} → apply
     (an agent that knows beats a server that guessed); `late: true`.
   - S = `cancelled` → `409 {"error":"conflict","status":"cancelled"}`.
   - Otherwise S final and O different → `409 {"error":"conflict","status":
     "<S>"}`. The agent logs and MUST NOT retry.
4. S ∈ {`leased`, `spooled`, `queued`}: apply O.
   - `queued` happens when the lease expired and nobody has claimed the job
     again yet; the ack is applied and `late: true` is returned.
   - If another agent holds a newer lease and O is final, the job still
     closes with O (the earlier agent did the work; the later agent's own
     ack will then get `200` for the same outcome or `409` for a different
     one).
   - A job past `expires_at` still accepts the ack and records it
     (`late: true`). A label that physically came out is more useful to
     know about than an `expired` marker.
5. `spooled` with the same token repeated → `200 {"status":"spooled"}`
   (keeps the job alive; see section 10 for the silence timeout).

### 6.5 Reason codes

A server MUST accept any code that matches the pattern in 6.1 (agents may add codes) and SHOULD store and show
it next to `detail`. The codes the PrintDock agent sends:

| `reason` | with | Meaning |
|---|---|---|
| `expired`, `unsupported_route`, `unknown_printer`, `bad_content` | `rejected` | 5.5 |
| `bad_options` | `rejected` | the job's `options` hold a value this agent does not implement (9.2) |
| `unsupported_queue` | `failed` | the queue needs a dialog (`PORTPROMPT:`, `FILE:`, print-to-file driver); not touched (11.15) |
| `print_timeout` | `failed` or `uncertain` | no answer from the driver/spooler (or `lp`) within the hard timeout; the print process was killed (11.13) |
| `spool_timeout` | `failed` or `uncertain` | the job's system queue made no progress for `spool_timeout_s` and the job was cancelled: `failed` once the queue no longer lists it, `uncertain` when it still lists it (or cannot be asked) 30 s after the cancel. A queue that cannot be asked at all (spooler restarting) stops the no-progress clock instead of advancing it |
| `cancelled_in_queue` | `uncertain` | the job was cancelled or aborted in the system queue by someone else (CUPS job history says so; Windows: it left the queue while being deleted) |
| `spool_error` | `uncertain` | Windows: the job left the queue while its last status showed an error |
| `spool_lost` | `uncertain` | the job is no longer in the queue, and the agent could not watch that queue for a while before (spooler restarting or unreachable, agent restarted) and has not seen the job listed again since. A restarting spooler can lose jobs and reuse their numbers, so after such a gap only a positive answer counts as `printed`: CUPS job history with the job's title, or the Windows `PRINTED`/`COMPLETE` bits |
| `printer_unreachable` | `failed` | the TCP connection to the printer was refused, timed out or had no route |
| `printer_io_error` | `failed` or `uncertain` | the connection broke while sending: `failed` before the first byte, `uncertain` after some bytes left |
| `bad_printer_address` | `failed` | the configured `address` is not `host:port` |
| `spooler_error`, `driver_error` | `failed` | a Windows spooler / printer driver call failed (the OS message is in `detail`) |
| `unknown_queue`, `queue_not_accepting`, `bad_file` | `failed` | `lp` reached a running cupsd and it refused the job: no such queue, queue not accepting jobs, unreadable file (the CUPS message is in `detail`) |
| `cups_error` | `failed` or `uncertain` | `lp`/CUPS refused the job for another reason (`uncertain`: `lp` exited 0 without a job id and no job with the job's title is in the queue) |
| `cups_interrupted` | `uncertain` | `lp`'s connection to a running cupsd broke off (broken pipe, reset) and no job with the job's title is in the queue |
| `spooler_unavailable` | none, then `failed` or `uncertain` | the system spooler could not be reached before it took anything (cupsd stopped or restarting); the job is given back (see below) - acked only when that has lasted 10 minutes |
| `render_error` | `failed` | the PDF could not be opened or rasterised |
| `print_process_failed` | `failed` or `uncertain` | the child process that prints died or could not start |
| `content_unavailable` | `failed` | `content_url` answered 4xx |
| `agent_stopped` | `uncertain` | the agent stopped between claim and send (11.3) |
| `internal_error` | `failed` | an unexpected exception in the agent |
| `printer_error` | `failed` | any other transport error |

**Giving a job back.** PAP/1 has no call that releases a job (`release` in this document is the update check).
When the spooler provably took nothing (CUPS: `lp` failed without a job id and cupsd does not answer an IPP request
now - asked over IPP, not by reading the localised `lpstat -r`; or `lp` could not even resolve the destination,
"does not exist", while cupsd was starting, and the queue now exists and holds no job with this job's title), the
agent neither acks nor extends: the job stays
`leased`, and the next `claim` of that printer redelivers it with the same token (5.4 rule 2) - or, if the lease
lapsed first, requeues it with `attempt + 1`. The agent holds that printer out of `claim` for 5 s, doubling to 60 s,
and reports the printer as unavailable locally. Nothing is acked, so a server never sees `failed` for a job that
never reached a spooler.

Every spooler job carries the PAP `job_id` as its title (CUPS `lp -t`) or document name (Windows). Spooler job
numbers are not identities: cupsd reuses them after a restart that lost its job files, and Windows numbers start
again after the Spooler service restarts. Whenever the agent reads a job's state or end by its number, a job of that
number with a different title is not its job.

Every CUPS job is submitted with the PAP `job_id` as its title. A redelivered job that was given back is first
looked up in the queue by that title (job history included): found -> the agent follows that CUPS job (`spooled`)
and never prints it again; cupsd still down -> given back again; not found -> printed. The same lookup decides when
`lp` broke off or hung while cupsd answers: found -> `spooled`, else `uncertain` (never given back). A queue that
does not exist while cupsd answers is `failed` / `unknown_queue` at once.

Giving back is bounded: 10 minutes after the job was first given back, the next give-back is acked instead -
`failed` / `spooler_unavailable` when every attempt failed to connect, `uncertain` when any attempt broke off
mid-transfer (cupsd may have created the job just before it went away).

**Keeping a spooled job alive.** While it follows a `spooled` job, the agent repeats the `spooled` ack every
(`spool_timeout_s` + `lease_s`) / 3 seconds (6.4 rule 5), so a long queue or a spooler that cannot be asked for a
while is not turned `uncertain` by the silence rule of section 10. It stops after 10 minutes without any answer
from that job's queue; the server's silence rule then applies.

## 7. Fetching job content — `GET /jobs/{job_id}/content`

When a job carries `content_url` instead of `content_base64`, the agent
fetches it with the same `Authorization` and `X-PAP-Version` headers. The URL
is absolute and on the same origin as `server_url` (agents MUST refuse other
origins).

- `200` with the raw bytes, `Content-Type` as appropriate
  (`application/pdf`, `application/octet-stream`), and `Content-Length`.
- `404` when the job is not currently leased or spooled to this agent, or has
  reached a final state.
- The URL is valid for the duration of the lease and may be fetched more
  than once.

Servers announce the ability with `server_capabilities.content_url: true`;
agents MUST implement it regardless.

## 8. Updates — `GET /release`

`GET /release?os=<os>&arch=<arch>&current=<version>`

- `204` — nothing to install (a server without an update channel always
  answers `204`).
- `200`:

  ```json
  {"version": "1.1.0", "required_min": "1.0.0",
   "url": "https://wms.example.com/releases/printdock-1.1.0-windows-amd64.exe",
   "sha256": "…", "signature": "base64-ed25519-signature", "server_time": "…"}
  ```

The agent asks once an hour. What it does with the answer (download, verify
`sha256` and `signature`, swap binaries) is outside the protocol; PAP/1 only
fixes the shape so that update support can be added to agents later without
a protocol change. An agent MAY ignore the response entirely.

## 9. Job formats, options and routes

### 9.1 Formats

| `format` | Content | What the agent does |
|---|---|---|
| `zpl` | Zebra Programming Language text | passes the bytes through unchanged to a printer with `language: zpl` (or a driver queue set to raw) |
| `epl` | Eltron Programming Language text | passes through to `language: epl` |
| `raw` | arbitrary bytes for the printer | passes through to any printer whose `language` is not `driver`; the agent does not check the language |
| `pdf` | a PDF document | either hands it to a system driver (`language: driver`, `capabilities.pdf_driver`) or rasterises it to the printer's language (`capabilities.raster_languages`) and sends the result as raw bytes |
| `png` | reserved for PAP/1.x; servers MUST NOT send it unless the agent listed it | — |

### 9.2 `options` (PDF only)

| Option | Default | Meaning |
|---|---|---|
| `copies` | `1` | number of copies |
| `fit` | `page` | `page` — scale to the printer's `media` keeping aspect ratio, up or down, centred; `shrink` — scale **down** to fit the `media` keeping aspect ratio, never up: a page smaller than the media prints 1:1 (real size, so barcodes keep their size), centred; `none` — 1:1 at `dpi`, top-left |
| `rotate` | `0` | `0`, `90`, `180`, `270` degrees clockwise; `auto` — 90° clockwise when the page's orientation (portrait/landscape) differs from the `media`'s, otherwise 0; a square page or square media is not rotated |

`shrink` and `auto` were added without a version change and are sent only
to agents that list `shrink` / `auto_rotate` in `capabilities.layout`
(4.1); every other agent receives `page` / `0`, which it always
understood. An agent that receives a value it does not implement acks
`rejected` / `bad_options`. A server that creates jobs without explicit
`fit`/`rotate` SHOULD fill them from the printer's `fit`/`rotate` (4.3) and
otherwise use `shrink` and `auto`. `rotate` is applied before `fit`, so
`auto` picks the orientation and the fit then scales the turned page. The
agent places the page itself, identically for raster languages and for
`spooler`/`cups` drivers; it MUST stop the system from scaling or rotating
the page again (no driver or CUPS fit-to-page / auto-rotate on top).
`page` and `shrink` fit the page into the area that actually prints and
centre it there: the printable area where the agent knows it (the Windows
driver's printable area, CUPS queue margins from
`media-*-margin-supported`), otherwise the whole `media` (raster
languages). Nothing of a page that fits is cut off, at the price of a label
a few percent smaller on a driver with unprintable edges. `none` is 1:1 from
the top-left corner of the paper, clipped where the device cannot print.

A PDF with several pages prints all pages in order; `fit` and `rotate`
apply to each page separately; `copies` repeats the whole document
(collated). `media` of a `driver` printer is the paper the agent asks the
driver for (e.g. `210x297mm` for A4); the driver's own default is used when
it does not offer that size, and the agent reports it in `detail`.

Options are absent (or ignored) for pass-through formats, as the content
already is what the printer executes.

### 9.3 Route table

| format \ printer | `language: zpl/epl/ezpl/escpos` on `socket` or raw queue | `language: driver` on `spooler`/`cups` |
|---|---|---|
| `zpl` | ok if `language: zpl` | ok only if the agent sends it raw to the queue (`cups`: `lp -o raw`, `spooler`: RAW datatype) and the queue is known to pass bytes; otherwise `unsupported_route` |
| `epl` | ok if `language: epl` | same as above |
| `raw` | ok | same as above |
| `pdf` | ok only if the printer language is in `capabilities.raster_languages` | ok if `capabilities.pdf_driver` |

An agent never guesses: a route not in the table is `rejected` /
`unsupported_route` immediately, without trying.

### 9.4 How printers are connected

| Connection | How the agent reaches it |
|---|---|
| Network label printer (ZPL/EPL…) | `socket` to `address:9100`, or a system queue |
| Network office/laser printer (A4) | a system queue with the vendor's or the OS class driver (`spooler` / `cups`, `language: driver`); `socket` only for pass-through `raw` content the server already prepared for that printer (PCL, PostScript) |
| **USB printer (any kind)** | **always through a system queue** (`spooler` / `cups`) — the agent never opens USB devices itself. A USB queue is addressed by `system_name` like any other; RAW and driver routes work the same as for network queues |

## 10. Server-side job states

```
                 claim                 ack
  queued ───────────────► leased ───────────────► printed
    ▲                       │                 ├─► spooled ──► printed | failed | uncertain
    │   lease_until passed  │                 ├─► failed
    └───────────────────────┘                 ├─► uncertain
                                              └─► rejected  (stored as failed + reason)
  any non-final ──operator──► cancelled
  queued | leased past expires_at ──reported as──► expired   (computed, not stored)
```

- `expired` is **computed** when reading: a job in `queued` or `leased`
  whose `expires_at` has passed. It is never handed out. A late `printed`
  ack on such a job is still recorded as `printed` with `late: true`.
- **At most once (server policy, per job).** A server MAY create a job that
  must never be handed out again once a lease on it has expired: the agent
  may have sent the bytes to the printer and lost the ack, and for some
  documents (courier labels) a second copy is worse than a missing one,
  which an operator reprints deliberately. Lease expiry then sets such a job
  to `uncertain` with `reason: lease_expired` instead of `queued`. Nothing
  changes on the wire: redelivery of a lease that has **not** expired
  (5.4 rule 2: same `lease_token`, `redelivered: true`) still happens, and a
  final ack from the agent still replaces the server's `uncertain` (6.4).
  `lease_expired` is set by the server only; agents do not send it.
- Lease expiry moves **only `leased`** back to `queued`. **`spooled` never
  returns to the queue**: the bytes are already in a spooler and reissuing
  the job would print twice. If a `spooled` job receives no ack for longer
  than `spool_timeout_s + lease_s`, the server sets it to `uncertain` (not
  `queued`).
- `cancelled` is set by the server operator (for example when the same
  parcel is reprinted and the old job is invalidated). A cancelled job is
  never handed out; acks on it answer `409`.
- A `failed` job is not retried automatically unless the server has its own
  policy (`retryable`); that policy is outside PAP/1.

## 11. Agent requirements

These are normative for an agent claiming PAP/1 conformance.

1. **Journal.** The agent keeps a durable, append-only local record per job
   with at least the transitions `claimed`, `sent`, `acked` (and the
   `lease_token`, outcome, and spooler id if any). Each entry is flushed to
   stable storage (`fsync` or equivalent) before the next step is taken.
2. **No double print.** A job whose journal entry says `sent` is never sent
   again — on redelivery, after a lost ack, after a restart. The agent acks
   from the journal instead.
3. **Crash recovery.** At start-up the agent replays its journal: entries
   `sent` without `acked` → send the ack now (outcome as recorded);
   entries `claimed` without `sent` → ack `uncertain` (it cannot know
   whether bytes left the machine). Either way the job is not printed again.
4. **Single instance.** Only one agent process may run per state directory.
   A second process MUST exit promptly (within 5 s) with a distinct exit code
   and MUST NOT claim or print anything.
5. **Ack delivery.** Acks that cannot be delivered (network error, `5xx`)
   are queued and retried with backoff until delivered or answered with
   `4xx`. The agent keeps claiming meanwhile — one unreachable ack does not
   stop the station — except that it MUST NOT claim new work while an ack for
   a `sent` job is still undelivered **and** the journal is not durable (in
   practice: journal first, then ack, then move on).
6. **No retry on 409 / 4xx.** A `409` on ack is logged and dropped. The agent
   continues with other jobs.
7. **Expiry and content checks** as in 5.5.
8. **Clock offset** applied from `server_time`, or `expires_in_s` used
   instead of `expires_at` (either is acceptable).
9. **Version mismatch** stops the agent (section 1).
10. **Revocation** stops claiming and keeps retrying `hello` (section 2).
11. **Poll discipline**: never faster than `poll_interval_s` / `Retry-After`;
    `hello` at `hello_interval_s`.
12. **Printer errors verbatim** in `detail` of `failed`; no label content in
    logs; `title` not logged when `title_sensitive`.
13. **Bounded print attempts.** Handing a job to a transport that can block
    inside a driver or spooler (Windows GDI/spooler, CUPS) MUST have a hard
    timeout, and the work MUST be killable (a separate process; a stuck thread
    cannot be interrupted). On timeout the agent cancels the spooler job when it
    knows its id, and acks `uncertain` when a spooler job existed (a page may
    already have reached the printer: a human decides) or `failed` when nothing
    had been handed over. It never lets the lease lapse silently and never
    relies on redelivery to recover. This rule covers an attempt in which
    bytes may have been handed over (a CUPS `lp` that hangs follows it:
    `uncertain` unless the job is found in the queue by its title). Giving a
    job back (6.5) is a different case: the spooler provably took nothing, so
    redelivery cannot print twice, and it is bounded to 10 minutes.
14. **Lease extension while printing.** With `server_capabilities.lease_extend`
    the agent calls `extend` (5.6) for every job it is still printing.
15. **No unattended printing to interactive queues.** Queues that need a dialog
    (port `PORTPROMPT:` or `FILE:`, print-to-file drivers) are reported
    `unsupported` in `seen_printers`; a job for such a printer is acked `failed`
    at once with a clear `detail`, without touching the queue.
16. **Failure reasons.** An ack with outcome `failed` or `uncertain` carries a
    machine code in `reason` (6.5) next to the verbatim `detail`.
17. **Discovery is polite** (section 14): on request only, local RFC 1918
    subnets only, bounded in size, time and concurrency, no data ever sent to
    a print port, and in its own thread so that printing is unaffected.

## 12. Error catalogue

Every error body: `{"error": "<code>", "message": "<text for humans>",
"server_time": "…"}` plus code-specific fields.

| HTTP | `error` | When | Agent reaction |
|---|---|---|---|
| 400 | `bad_request` | malformed JSON, missing required field, empty `printer_ids` | log, do not retry the same request |
| 400 | `unsupported_version` | missing or non-1 `X-PAP-Version` | stop (section 1) |
| 401 | `unauthorized` | missing/unknown key | stop claiming; retry `hello` every 60 s |
| 401 | `revoked` | key revoked | same |
| 403 | `bad_lease_token` | token never issued for this job (`ack`, `extend`) | log, drop the ack |
| 404 | `not_found` | unknown job; content no longer available; `extend` on a job not leased to this agent | log, drop |
| 409 | `conflict` (+ `status`) | job closed with a different final outcome, or cancelled; `extend` on a job that is no longer leased with this token | log, drop the ack (or stop extending), continue |
| 429 | `rate_limited` | too many requests; `Retry-After` header | wait, retry |
| 5xx | `server_error` | server fault | retry with backoff |

## 13. Complete examples

A full cycle for a ZPL label on a raw socket printer.

**1. `POST /hello`** (request/response as in section 4; the server answers
with `pr_label1`, `transport: socket`, `address: 10.0.0.15:9100`,
`lease_s: 60`, `poll_interval_s: 2`.)

**2. `POST /jobs/claim`**

```
POST /api/printing/agent/v1/jobs/claim HTTP/1.1
Authorization: Bearer pa1_7f3k2m9q_Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWo
X-PAP-Version: 1
User-Agent: printdock/1.0.3 (linux/amd64)
Content-Type: application/json

{"printer_ids": ["pr_label1"], "max": 1}
```

```
HTTP/1.1 200 OK
X-PAP-Version: 1
Content-Type: application/json

{"job_id": "pj_01JBD6W8K2", "printer_id": "pr_label1", "printer_role": "label",
 "format": "zpl",
 "content_base64": "XlhBCl5GTzUwLDUwXkEwTiw0MCw0MF5GRFRFU1ReRlMKXlha",
 "content_sha256": "0b4a1d0a7c7e0d2e3f4a5b6c7d8e9f00112233445566778899aabbccddeeff00",
 "lease_token": "lt_c0ffee01", "lease_until": "2026-10-30T13:42:34Z",
 "expires_at": "2026-10-30T13:56:00Z", "expires_in_s": 866,
 "attempt": 1, "redelivered": false,
 "title": "DPD 1234567890 (order 98765)", "title_sensitive": false,
 "server_time": "2026-10-30T13:41:34Z"}
```

**3. The agent** journals `claimed`, opens a TCP connection to
`10.0.0.15:9100`, writes the bytes, half-closes, journals `sent`.

**4. `POST /jobs/pj_01JBD6W8K2/ack`**

```json
{"lease_token": "lt_c0ffee01", "outcome": "printed",
 "detail": "socket 10.0.0.15:9100, 44 B, 0.004 s", "agent_time": "2026-10-30T13:41:35Z"}
```

```json
{"status": "printed", "late": false, "server_time": "2026-10-30T13:41:35Z"}
```

**5. Lost response to the claim.** Suppose the response in step 2 never
reached the agent. The agent's next claim (2 s later) returns the same job
with `redelivered: true`, the same `lease_token` and `attempt: 1`. The agent
has no journal entry for it, so it prints once — correct, since it had never
printed.

**6. Lost ack.** Suppose the request in step 4 never reached the server. The
agent retries the ack after 1 s; it also keeps claiming, and the claim may
return the job again (`redelivered: true`). The journal says `sent`, so the
agent only acks. Whichever ack arrives first closes the job; the other gets
`200` with the same status.

**7. Crash between send and ack.** The process is killed after journaling
`sent`. On restart it finds `sent` without `acked`, sends the ack with the
recorded `lease_token` and outcome, and never reprints. If it had been killed
after `claimed` but before `sent`, it acks `uncertain` instead and an operator
decides.

**8. Rejected job.**

```json
{"lease_token": "lt_c0ffee02", "outcome": "rejected", "reason": "unsupported_route",
 "detail": "pdf on pr_label1 (language epl): no driver, no raster for epl"}
```

```json
{"status": "failed", "late": false, "server_time": "…"}
```

**9. Nothing to do.**

```
HTTP/1.1 204 No Content
X-PAP-Version: 1
Retry-After: 2
```

---

## 14. Network printer discovery

Adding a printer today means typing an address. With discovery the operator (or an administrator in the server UI)
asks the agent to look around its own network, and the result is a list the server UI can offer: "found 10.0.0.15,
Zebra ZD421, port 9100, not configured yet". Added in PAP/1 without a version change; an agent without the
capability and a server without the endpoint ignore it.

```
administrator ──POST /admin/…/discover──► server      (admin API, not part of PAP)
agent ◄── hello 200 {"discover": "dr_1"}  or  claim 204 + X-PAP-Discover: dr_1 ──── server
agent scans (own thread) ──► POST /discovery {"request_id": "dr_1", …} ──► server clears the request
```

### 14.1 Request

The server asks by putting the request id into answers the agent already gets: `discover` in the `hello` `200` body
and in a `claim` `200` body, header `X-PAP-Discover` in a `claim` `204` (4.2, 5.2, 5.3). It does so only while the
request is open and only for an agent that announced `capabilities.discovery`. The agent takes each id **once**
(running, queued or answered): the same id repeated in later answers starts no second scan. A new id while a scan is
running or within the minimum interval is queued, not refused. A paused agent (4.1 `state`) does not `claim`, so it
sees the request in its next `hello`.

### 14.2 `POST /discovery`

Sent by the agent when a scan has finished (with `request_id` of the request it answers, or `null` for a scan the
operator started locally). Body, at most 256 KiB:

```json
{
  "request_id": "dr_1",
  "agent_time": "2026-10-30T13:41:36Z",
  "started_at": "2026-10-30T13:41:30Z",
  "finished_at": "2026-10-30T13:41:35Z",
  "duration_s": 5.0,
  "subnets": ["10.88.0.0/24"],
  "subnet_notes": [{"subnet": "10.20.0.0/16", "reason": "clamped_to_10.20.4.0/22"}],
  "hosts_probed": 253,
  "truncated": false,
  "error": null,
  "discovered_printers": [
    {"address": "10.88.0.13", "ports": [9100], "model": "Zebra ZD421-203dpi", "vendor": "Zebra",
     "hostname": "label-pack-3.local", "sources": ["tcp", "mdns", "snmp"], "services": ["_pdl-datastream._tcp"],
     "mac": "02:42:0a:58:00:0d", "already_configured": false}
  ]
}
```

| Field | Meaning |
|---|---|
| `request_id` | the id from 14.1, or `null` |
| `subnets` | the networks that were scanned (CIDR) |
| `subnet_notes[]` | networks that were skipped or narrowed, with `reason`: `not_private`, `too_wide`, `not_a_network`, `no_hosts`, `too_many_networks`, `clamped_to_<cidr>` |
| `hosts_probed` | hosts whose ports were all tried |
| `truncated` | `true` if the time limit or a stop ended the scan early; the list may be incomplete |
| `error` | `null`, `no_private_subnet` (the machine has no RFC 1918 interface) or `scan_failed: …` |
| `discovered_printers[]` | at most 256, sorted by address. `address` (IPv4 string), `ports` (list of ints) and `already_configured` (boolean) are required; `model`, `vendor`, `hostname`, `mac` may be `null`; `sources` is a list of `tcp`, `mdns`, `snmp`; `services` lists the mDNS service types that advertised the printer |
| `ports` | TCP ports found open among 9100 (raw), 515 (LPD), 631 (IPP), plus the port an mDNS service advertised |
| `already_configured` | the address equals the host of a configured socket printer or appears in the port name of a system queue |

Response `200 {"status": "stored", "matched": true|false, "server_time": "…"}`. `matched` is `true` when
`request_id` was the open request (which is now closed). The call is idempotent: a repeat stores the same result and
answers `matched: false`. `400 bad_request` for a malformed body. The server keeps the **latest** result per agent;
history is its own business.

### 14.3 What the agent does (normative limits)

* **Only on request**: an operator action (tray menu, status page) or 14.1. Never by itself, never periodically.
* **Where**: the RFC 1918 networks (10/8, 172.16/12, 192.168/16) of the machine's own IPv4 interfaces. A network wider
  than the limit (default `/22`, 1022 hosts) is cut to the `/22` block around the interface address; at most 4
  networks. Loopback, link-local and public addresses are never scanned. An explicit list (`discovery.subnets`) must
  also be private and no wider than the limit.
* **How**: (1) mDNS PTR questions for `_pdl-datastream._tcp`, `_printer._tcp`, `_ipp._tcp`, `_ipps._tcp` to
  224.0.0.251:5353 (answered by unicast, nothing is listened on 5353); (2) a TCP connect to 9100, 515 and 631 of every
  host that is closed again **without sending a byte**; (3) SNMP v2c, then v1, community `public`, read-only GET of
  `sysDescr`, `sysObjectID`, `sysName`, `hrDeviceDescr.1`, `prtGeneralPrinterName.1` (model, vendor), sent only to
  hosts that showed an open port or an mDNS record. A host is listed only if (1) or (2) found something.
* **Bounds** (defaults, configurable): 64 concurrent connects, 0.3 s per connect and per SNMP attempt, 60 s for the
  whole scan (then `truncated: true`), at least 30 s between the end of one scan and the start of the next (a local
  request inside that time is refused; a server request is queued).
* **Own thread**; the printing threads, `claim`, `extend` and acks continue unaffected. Scanning is allowed while
  printing is paused: it sends no print data and is not a claim.
* The result is sent to the server and is also shown locally (status page, tray balloon).
* Nothing is ever configured automatically: the operator or administrator decides what to do with the list.
