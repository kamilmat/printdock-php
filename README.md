# printdock/server

A framework-independent PHP library with the **server side of the PAP/1 protocol** (Print Agent Protocol), which the
PrintDock print agent uses to poll the server for print jobs. The host application writes only adapters (storage in
its own database, its own screens); the protocol itself — job issuing rules, leases, acknowledgements, error codes —
lives here.

- Normative specification: [`docs/PROTOCOL.md`](docs/PROTOCOL.md). Where the text is unclear, the conformance suite
  `tests/conformance/server_conformance.py` decides (black box, checks any PAP/1 server).
- PHP `>=8.2` (tested on 8.2 and 8.5). `require` contains only `psr/clock`. PSR-15 is optional (`suggest`).
- Licence: proprietary for now (no permission to use without consent).

## Layers

```
src/
  Domain/        protocol rules without I/O: Agent, AgentKey, Job (+ lease and ack rules), JobState, Outcome,
                 ReasonCode, Printer, ErrorCode/ProtocolError (error catalogue, section 12), Time
  Port/          interfaces implemented by the host: AgentRepository, JobRepository, DiscoveryStore,
                 ReleaseProvider (optional); the clock is PSR-20 (Psr\Clock\ClockInterface)
  Application/   PapServer (one method per endpoint, no HTTP types), PapAdmin (agent registration,
                 job queueing), request and response objects
  Http/          PapRouter (handle($method, $path, $headers, $body): HttpResponse), Psr15Handler (optional)
  Adapter/
    InMemory/    in-memory adapters (tests, examples) + MutableClock
    Pdo/         PDO adapters: SQLite and MySQL 8+/MariaDB 10.6+ (row locks), PdoSchema
resources/schema/{sqlite,mysql}.sql   schema, also a template for your own migration
examples/standalone/                  working application under `php -S` (PDO SQLite + mini admin)
docs/PROTOCOL.md                      PAP/1 protocol specification
tests/                                PHPUnit; tests/conformance/ = server conformance suite (Python 3.9+, no dependencies)
```

All rules concerning *a single job* (who can get it, redelivery, lease expiry, ack, extend) live in `Domain\Job`.
Repositories only pick a candidate and persist; an adapter does not reimplement the rules. Every state change records
an event (`JobEvent`) in `Job`, which the repository appends to the history in the same transaction.

## How to plug it into an application

1. **Implement the ports** (or use the PDO adapters if they are enough):

   | Port | What it must guarantee |
   |---|---|
   | `AgentRepository` | `findByKeyId()` via a unique index (one query per request). `save()` writes only administrative fields (name, settings, printers, key revocation); `recordHello()`, `recordActivity()` and `recordClaimResult()` are separate, narrow writes so that concurrent agent requests do not overwrite the administrator's work. `delete()` removes only the agent row. |
   | `JobRepository` | **`claimNext()` is atomic**: in one transaction with row locks (`SELECT … FOR UPDATE`, and for new-lease candidates `FOR UPDATE SKIP LOCKED`, MySQL 8 / PostgreSQL), so that two concurrent claims cannot lease the same job. Ordering "oldest first" = insertion order (auto-increment, not `created_at`). `modify($id, $callable)` loads the job with a write lock, calls the callable and saves; if the callable throws, nothing is saved (this is how ack with 409 works). `sweep()` returns expired leases to `queued` and turns silent `spooled` jobs into `uncertain` — through `Job::applyTimeouts()`, not a bulk `UPDATE`, because every change is an event. SQL does the selection, **`Job` does the rules** (`isClaimableFor`, `isRedeliverableTo`, `lease`, `redeliver`, `isClosedBy`). **History:** `add()`, `sweep()`, `claimNext()` and `modify()` append `Job::pullEvents()` to the event log in the same transaction; `history()` and `findSummary()` read **without the content column**, and `modify()` and `sweep()` do not need it either — the SQL adapter loads the job without it and gives `Job::fromData()` a lazy-loading function (`content()` reads on first use), so that ack does not pull a multi-megabyte PDF; `find()` and `claimNext()` return the content; `events()` is the job's timeline. `hasOpenJobs()`, `purgeContent()` and `purgeHistory()` serve station removal and retention. |
   | `DiscoveryStore` | an open scan request and the latest result per agent; `storeResult()` closes the request atomically and returns `matched` only once; `delete()` forgets the agent's data. |
   | `ReleaseProvider` | optional; without it `GET /release` always answers 204. |

2. **Build `PapServer`** and mount the router:

   ```php
   $server = new PapServer($agents, $jobs, $discovery, $clock, new PapServerConfig(
       contentBaseUrl: 'https://wms.example.com/api/printing/agent/v1',   // needed only for content_url
   ));
   $router = new PapRouter($server, '/api/printing/agent/v1', onError: fn (\Throwable $e) => $logger->error((string) $e));

   $response = $router->handle($method, $requestTarget, $headers, $rawBody);   // HttpResponse: status, headers, body
   ```

   Or as PSR-15: `new Psr15Handler($router, $psr17ResponseFactory, $psr17StreamFactory)`.
   The router always adds `X-PAP-Version: 1`, checks the version **before** authentication, and turns unexpected
   exceptions into `500 server_error` without the exception text (the text goes only to `onError`).

3. **The operator side** (agent registration, queue, cancellation, printer scan) is done by `PapAdmin`. Its
   `registerAgent()` result contains the key in plain text — exactly once; the database holds only the SHA-256 of the
   secret.

   **Removing a station:** `$admin->deleteAgent($id)` is allowed only for an agent with a **revoked key**
   (otherwise `AdminException::AGENT_NOT_REVOKED`) and without unfinished jobs — `queued`, `leased`, `spooled`
   (`AGENT_HAS_OPEN_JOBS`; you have to wait or cancel). It removes the agent row and its printer scan data;
   **its jobs stay in the history** (with the station name from the time of creation), but **without content** (labels
   may contain personal data). It first applies the time limits of its jobs (a dead station will not sweep on its
   own), so a lease past its deadline returns to the queue and a silent `spooled` becomes `uncertain`. Then the port
   calls in this order: job content, scan, agent; if interrupted midway they can be repeated. A host that wants a
   single transaction wraps the whole call.

   **History and retention:**

   ```php
   $page = $admin->history(new HistoryQuery(agentId: $id, states: [JobState::Failed], text: 'DPD', limit: 50));
   foreach ($page->jobs as $job) { /* JobSummary: no content, with content_size, agent_name, printer_name */ }
   $next = $admin->history(new HistoryQuery(agentId: $id, before: $page->nextBefore));   // older page
   $timeline = $admin->jobEvents($jobId);   // list<JobEvent>, oldest first
   $detail = $admin->findSummary($jobId);   // ?JobSummary: a single job without content, for a detail screen

   // from the host's scheduler (suggested: content after 7 days, history after a year)
   $admin->purgeContent(new \DateTimeImmutable('-7 days'));
   $admin->purgeHistory(new \DateTimeImmutable('-365 days'));
   ```

   Filters: station, printer, states (as stored — `expired` is still `queued`/`leased`), `created_at` from
   (inclusive) / to (exclusive), text (substring of the title — **not** for `title_sensitive`, search must not reveal
   a hidden title — `job_id` or `reference.source_ref`; Polish characters work on MySQL too). Paging by `seq`, newest
   first, `limit` 1–200 (default 50). "Closed" for retention = a final state or `queued` past `expires_at`.
   `purgeContent()` and `purgeHistory()` first run `sweepAll()` — they apply the time limits of the jobs of all
   stations, otherwise the `leased`/`spooled` jobs of a station that stopped polling would never close.
   Events (`enqueued`, `leased`, `redelivered`, `lease_expired`, `acked`, `spool_silence`, `cancelled`) do not
   copy the title (it may be sensitive); `acked` carries `outcome`, `reason`, `detail`, `spool_job_id`, `late`. A
   repeated identical ack creates no event; a repeated `spooled` with new content (e.g. `spool_job_id`) does.

   **Label size (PDF `options`, section 9.2):** a PDF job without `fit`/`rotate` takes them from the printer
   (`Printer::$fit`, `Printer::$rotate`), and without those `fit: shrink` (shrink, never enlarge — barcodes at real
   size) and `rotate: auto`. An agent that did not report `capabilities.layout` (`shrink`, `auto_rotate`) gets
   `page` / `0` on claim — exactly what it got before; the stored job keeps what was asked for.
   Bad options in `enqueue()` → `AdminException::BAD_REQUEST`.

   ```php
   $registered = $admin->registerAgent('Packing 1', new AgentSettings(leaseS: 60), [$printer]);
   $job = $admin->enqueue(new NewJob($registered->agent->id(), 'pr_label1', 'label', JobFormat::Zpl, $zplBytes, title: 'DPD 123'));
   ```

### Symfony / Doctrine integration sketch (adapters and controller, not finished code)

```php
// Shortest: PDO from the DBAL connection. Adapters join the host's open transaction.
$pdo = $connection->getNativeConnection();                // Doctrine\DBAL\Connection -> \PDO
new PdoJobRepository($pdo); new PdoAgentRepository($pdo); new PdoDiscoveryStore($pdo);
```

Custom adapters (DDD + hexagon) implement the same ports on Doctrine entities; `Job::fromData()` / `toData()`
give a flat array for mapping, and `claimNext()` in DBAL is roughly:

```php
$this->connection->transactional(function () use (...) {
    $row = $this->connection->fetchAssociative(
        "SELECT * FROM job WHERE agent_id = ? AND state = 'queued' AND printer_id IN (?) AND not_before <= ? AND expires_at > ?
         ORDER BY seq LIMIT 1 FOR UPDATE SKIP LOCKED", [...], [..., ArrayParameterType::STRING]);
    $job = Job::fromData($this->hydrate($row));
    if ($job->isClaimableFor($agentId, $printerIds, $now)) { $job->lease(LeaseToken::generate(), $now, $leaseSeconds); $this->save($job); }
});
```

Controller (Symfony 8), the PSR-20 clock is `Symfony\Component\Clock\ClockInterface`:

```php
#[Route('/api/printing/agent/v1/{path}', requirements: ['path' => '.*'], methods: ['GET', 'POST'])]
public function __invoke(Request $request): Response
{
    $r = $this->router->handle($request->getMethod(), $request->getRequestUri(), $request->headers->all(), $request->getContent());
    return new Response($r->body, $r->status, $r->headers);
}
```

Disable the firewall for this path (the agent authenticates with its own `pa1_…` key; `PapRouter` returns 401 itself).
Linking to user accounts, permissions, screens and domain events (e.g. "job printed") stays on the application
side — the library does not publish events.

## Running

The host has neither PHP nor Composer; everything runs in Docker (`php:8.5-cli`, image built from `docker/Dockerfile`):

```
make install        # composer install
make test           # PHPUnit (adapters: memory, SQLite; MySQL variants are skipped)
make stan           # PHPStan, level max
make cs             # php-cs-fixer, PSR-12 (make cs-fix fixes)
make conformance    # PAP/1 conformance suite against examples/standalone
make test-mysql     # the same adapter tests on a throwaway MySQL 8.4 (own Docker network, cleaned up)
make all            # install + test + stan + cs + conformance
PHP_VERSION=8.2 make all    # on a different PHP version
```

### Example

```
export PRINTDOCK_ADMIN_TOKEN=$(openssl rand -hex 16)
php -S 127.0.0.1:8099 examples/standalone/public/index.php
curl -s -H "X-Admin-Token: $PRINTDOCK_ADMIN_TOKEN" -XPOST localhost:8099/admin/agents -d '{"name":"demo","printers":[{"printer_id":"p1","role":"label","transport":"socket","address":"10.0.0.15:9100","language":"zpl","dpi":203,"media":"100x150mm"}]}'
curl -s -H "X-Admin-Token: $PRINTDOCK_ADMIN_TOKEN" -XPOST localhost:8099/admin/jobs -d '{"agent_id":"<agent_id>","printer_id":"p1","content_text":"^XA^FDhello^FS^XZ"}'
# agent: server_url = http://127.0.0.1:8099/pap/v1, key from the response to the first command
```

PAP lives under `/pap/v1`, the mini admin under `/admin` (the shape the conformance suite expects; header
`X-Admin-Token`, variable `PRINTDOCK_ADMIN_TOKEN`, required: without it the admin API answers 403, unless
`PRINTDOCK_ADMIN_OPEN=1`, which only the conformance run sets). Public origin for `content_url`: `PRINTDOCK_BASE_URL`
(default `http://127.0.0.1:8099`). Database file: `PRINTDOCK_DB` (default `var/standalone.sqlite`).
**Never expose the example to a network**: it is a test fixture, not a hardened service.

### Conformance

`tests/conformance/server_conformance.py` is a conformance suite for any PAP/1 server — pure Python 3.9+ with no
dependencies, changed together with `docs/PROTOCOL.md`. `make conformance` runs it twice against the example:
with `--admin-url` (all 25 tests, because the example has a test administration API) and as a black box
(`--url --key --enqueue-cmd`; tests that need server knobs are reported by the suite itself as SKIP). On a
foreign server: `python3 tests/conformance/server_conformance.py --url … --key pa1_… --printer-id … --enqueue-cmd '…'`.

## Decisions where the specification is ambiguous

- **Key**: the database holds the SHA-256 of the *secret* (as section 2 says). Comparison with
  `hash_equals`. An unknown `key_id` ends with 401 right away — the id is not secret.
- **`rejected`** requires `reason` (400 without it), but accepts **any well-formed** code
  (section 6.5: the server MUST accept any code matching the pattern).
- **`late`** is computed literally per 6.3: after `lease_until` or `expires_at`, with an old token or on a job
  that is back in `queued`. Side effect: the final ack of a `spooled` job is usually
  `late: true`, because the lease expired long ago.
- **Printer roles** are resolved by `PapAdmin::resolvePrinterId()` at queueing time; a job always carries a concrete
  `printer_id` (section 4.3), so `claim` resolves nothing.
- **Long polling is optional and off by default**: `new PapServerConfig(longPollMaxS: 20)` (0–60) advertises
  `long_poll_max_s` and holds a claim with `wait_s` for up to `min(wait_s, longPollMaxS)`, checking every 250 ms (the
  claim in its own short transaction; sweep only on the first attempt and then every `lease_s / 2`, because on SQLite
  each takes the write lock of the whole database; nothing is locked between attempts). It returns immediately when a
  job or a scan request appears. **Cost: every waiting station holds one PHP worker and one database connection** for
  the whole waiting time — enable only with spare workers, with `max_execution_time` above the limit and without a
  transaction wrapped around the PAP request. On SQLite each attempt is `BEGIN IMMEDIATE`, so with many stations
  MySQL is better. `Retry-After` = `max(poll_interval_s, time to the nearest `not_before`)`.
- **"At most once" delivery** (per job, optional): `new NewJob(..., redeliverAfterLeaseExpiry: false)`.
  By default (`true`) an expired lease returns to `queued` and the job goes to the station again — with courier
  labels this can mean a second label, because the agent may already have sent the bytes to the printer and lost the
  ack. With `false`, an expired lease closes the job as `uncertain` with `reason: lease_expired` (a code issued by the
  server, not by the agent; the `lease_expired` event with state `uncertain`), and the operator prints the missing
  label deliberately. Re-issuing a **non-expired** lease (same token, `redelivered: true`) stays, and the agent's
  final ack still replaces `uncertain` (6.4.3). The flag is in `Job::redeliversAfterLeaseExpiry()` and
  `JobSummary::$redeliverAfterLeaseExpiry`; in the database the column `redeliver_after_lease_expiry` (default 1,
  added by `PdoSchema::install()`), in `JobData` the optional key `redeliver_after_lease_expiry`.
- **Durations** are whole seconds (`lease_s`, `poll_interval_s`…).
- **Error order**: version → authentication → route (an unknown path is 404
  only after authentication) → body (400) → job 404 → token 403. Another agent's job is indistinguishable from an
  unknown one (404). A missing `lease_token` in `ack`/`extend` is 400.
- **A path outside the prefix** gets a plain 404 without `X-PAP-Version`; an unknown method on a known path is 404.
- **Printer scan result**: `address`, `ports`, `already_configured` are required (section 14.2), body ≤ 256 KiB otherwise 400.
- **`png`** can be queued only to an agent that reported it in `capabilities.formats`.
- **Claim rate limit** (`429 rate_limited`, on by default): a station that, after an **empty** claim without waiting,
  comes again sooner than `max(0.5 s, poll_interval_s × 0.5)` gets 429 with `Retry-After` (remaining seconds rounded
  up) — it ignores the interval it was given and would load the host. A rejected claim does not count as a claim, but
  records the station as alive (`last_seen_at`). Not limited: a claim right after an issued job,
  after a long poll that waited, and a claim offering a printer the empty claim did not offer (it has just become free).
  A new job for the station, a scan request and `hello` lift the limit immediately. The state is two columns of
  `printdock_agents` (`empty_claim_at`, `empty_claim_printers`), written only on change. `PapServerConfig(claimCooldown: false)`
  turns it off — only for test tools polling in bursts (the conformance suite in `make conformance`). Other limits
  (e.g. on `hello`) the host can throw from its own middleware via `ProtocolError::rateLimited()`.
- **MySQL deadlocks**: a transaction opened by the adapter that MySQL aborts with a deadlock (1213) or a lock wait
  timeout (1205) is rolled back and retried **once**; a host transaction the adapter joins is the host's to retry.
  With InnoDB's default `REPEATABLE READ` isolation, two concurrent claims of **one station** can deadlock on gap
  locks of the index they use to look for candidates (`SKIP LOCKED` does not skip gap locks); one retry usually
  suffices, but on a second deadlock in a row the claim ends with an exception (500, the agent tries again after
  `poll_interval_s`). `READ COMMITTED` on the PAP connection
  (`SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED`) takes no gap locks and avoids this; the adapters do not
  require it.
- **`options.copies`** also accepts a number as text (`"2"`), like earlier versions; it stores a number.

## Limitations of the first version

- The PDO adapters support SQLite and MySQL/MariaDB; PostgreSQL needs a new `PdoSupport` variant (SKIP LOCKED is there,
  but `INSERT IGNORE` and `DATETIME(6)` are not).
- `GET /release` only through the port; there is no built-in update channel or `POST /enroll` (reserved in PAP/1).
- Jobs from before 0.6 have no events (the timeline starts at the first change after the upgrade) and no `printer_name`.

## Changes in 0.7

- **Transport `ipp`** (PROTOCOL 9.5, added to PAP/1 without a version change): `PrinterTransport::Ipp`, a network
  printer the agent prints to directly over IPP, without a system driver (office A4 printers and IPP label printers).
  `address` is the printer URI `ipp://host[:port]/path` or `ipps://…`, checked by `IppUri::check()` when a `Printer`
  is built: an IP literal must be on the local network (loopback, link-local, RFC 1918, IPv6 ULA), a host name is left
  to the agent. Configure it only for agents whose `capabilities.transports` lists `ipp` (PrintDock agent 0.7.0+).
  New reason codes the agent may send: `media_empty`, `media_jam`, `printer_offline`, `printer_stopped`,
  `unsupported_document`, `unsupported_media` (stored like any other code). The scan result may carry `ipp_uri` per
  printer (kept verbatim).
- **"At most once" delivery**: `NewJob::$redeliverAfterLeaseExpiry` (default `true`, as before); new column
  `printdock_jobs.redeliver_after_lease_expiry`, `ReasonCode::LEASE_EXPIRED`. Host's own repositories: store the key
  `redeliver_after_lease_expiry` from `JobData` on insert (it does not change later).
- **`PapRouter`**: `InvalidArgumentException` gives 400 only from request parsing; the same exception from elsewhere
  (e.g. unreadable stored station data) is a 500 `server_error` and goes to `onError`.
- **`PapAdmin::cancel($id, $onlyIf)`**: with the second argument (e.g. `JobState::Queued`) it cancels only a job that
  is still in that state — checked under the same row lock as the change, otherwise `AdminException::CONFLICT`.
- **`purgeHistory()`** in the PDO adapter first locks the ids of closed jobs (in batches of 500, each batch its own
  transaction), then deletes their events and the jobs themselves by that list — a job closed in the meantime leaves
  no orphaned events. Do not call it inside your own transaction: the batches would join it and hold their locks to the end.
- **Request size**: `PapRouter` refuses bodies over 512 KiB (4th constructor argument `$maxBodyBytes`) with 400
  `bad_request`, after authentication; `hello` takes at most 256 `printers` and `seen_printers` and 64 entries per capability list.
- Queueing and a scan request always lift the claim limit, without reading the agent's state (no race with an empty claim).
- `Printer` accepts `rotate` as text (`"90"`) in the constructor and stores a number, like `fromArray()`.

- **`JobRepository` port**: new `findSummary(JobId): ?JobSummary`; `modify()` and `sweep()` in the PDO adapters no
  longer read the content column. `Job::fromData()` optionally takes a function that loads the content, and in
  `JobData` the `content` key may be `null` ("not loaded"); `Job::content()` loads it on first use.
- **`AgentRepository` port**: new `recordClaimResult()` (claim limit); `recordHello()` clears this state.
  `AgentData` has the optional keys `empty_claim_at`, `empty_claim_printers`.
- **`PapAdmin`**: new `findSummary()` and `sweepAll()`; `purgeContent()`, `purgeHistory()` and `deleteAgent()`
  first apply the job time limits. `PapServerConfig` has a third argument `claimCooldown` (default `true`).
- **History search**: text does not match the title of a `title_sensitive` job; text with Polish characters no
  longer breaks the query on MySQL (comparison with `job_id` only for ASCII text).
- **`PdoSchema::install()`** is resumable (backfills touch only rows not yet backfilled and run on every
  install) and tolerates two installs at once (SQLite: one `BEGIN IMMEDIATE` transaction; MySQL: "duplicate
  column/key" from a parallel `ALTER` = done). It adds the columns `printdock_agents.empty_claim_at` and
  `empty_claim_printers`. Run once at deployment, not on every request.

## Breaking changes in 0.6

The library is at version 0.x and both hosts are ours — breaking changes are allowed, but listed:

- **`PapAdmin::jobs()` removed** — it loaded the content of every job into memory. Replaced by
  `PapAdmin::history(HistoryQuery): HistoryPage` (a list of `JobSummary` without content; size in `contentSize`).
- **`JobRepository` port**: removed `findByAgent()` and `deleteFinishedByAgent()`; added `history()`, `events()`,
  `purgeContent()`, `purgeHistory()`; `add()`/`sweep()`/`claimNext()`/`modify()` must store events.
- **`deleteAgent()` no longer deletes jobs**: it clears their content, metadata and events stay.
- **`Job::enqueue()`** optionally takes an `Agent` (name snapshots, default printer options); `JobData` has new keys
  `agent_name`, `printer_name`, `content_size`, `content_purged`. After purging, `Job::content()` returns `''`.
- **PDF jobs always have `fit` and `rotate`** in the stored `options` (default `shrink`/`auto`, for old agents
  `page`/`0` on claim). Bad PDF options are rejected in `enqueue()`.
- **`PapServer`** has a new, optional last argument `$sleep` (`\Closure(int $microseconds): void`), and
  `PapServerConfig` a second argument `longPollMaxS`.

**Database upgrade:** `PdoSchema::install()` (idempotent) adds to an existing database the `printdock_jobs` columns
(`agent_name`, `printer_name`, `content_size`, `content_purged`, `source_ref`), the `printdock_job_events` table and the
indexes (`printdock_jobs_agent_created`, `printdock_jobs_state`, `printdock_jobs_finished`), and backfills old rows: content
size, station name from `printdock_agents`, `source_ref` from the `reference` JSON. A host with its own migrations
copies the same from `resources/schema/*.sql` and `PdoSchema` (`COLUMNS`, `INDEXES`, `BACKFILL`).
