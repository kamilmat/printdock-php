-- PrintDock PAP/1 server schema, SQLite dialect. Timestamps are UTC text 'YYYY-MM-DD HH:MM:SS.uuuuuu'
-- (lexicographic order = chronological order). Applied by PrintDock\Server\Adapter\Pdo\PdoSchema::install(),
-- which also upgrades a database created by an earlier version (new columns and indexes: see PdoSchema).

CREATE TABLE IF NOT EXISTS printdock_agents (
    agent_id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    key_id TEXT NOT NULL UNIQUE,
    key_hash TEXT NOT NULL,
    revoked INTEGER NOT NULL DEFAULT 0,
    poll_interval_s INTEGER NOT NULL,
    hello_interval_s INTEGER NOT NULL,
    lease_s INTEGER NOT NULL,
    spool_timeout_s INTEGER NOT NULL,
    printers_managed_by TEXT NOT NULL,
    printers TEXT NOT NULL,
    reported_printers TEXT NOT NULL,
    config_version TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_hello_at TEXT,
    last_claim_at TEXT,
    last_seen_at TEXT,
    hello_count INTEGER NOT NULL DEFAULT 0,
    claim_count INTEGER NOT NULL DEFAULT 0,
    last_hello TEXT,
    empty_claim_at TEXT,
    empty_claim_printers TEXT
);

CREATE TABLE IF NOT EXISTS printdock_jobs (
    seq INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id TEXT NOT NULL UNIQUE,
    agent_id TEXT NOT NULL,
    printer_id TEXT NOT NULL,
    printer_role TEXT,
    format TEXT NOT NULL,
    content BLOB NOT NULL,
    delivery TEXT NOT NULL,
    options TEXT,
    title TEXT,
    title_sensitive INTEGER NOT NULL,
    reference TEXT,
    state TEXT NOT NULL,
    created_at TEXT NOT NULL,
    not_before TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    attempt INTEGER NOT NULL,
    lease_token TEXT,
    lease_tokens TEXT NOT NULL,
    lease_until TEXT,
    leased_at TEXT,
    last_contact_at TEXT,
    finished_at TEXT,
    outcome TEXT,
    reason TEXT,
    detail TEXT,
    spool_job_id TEXT,
    late INTEGER NOT NULL,
    agent_name TEXT,
    printer_name TEXT,
    content_size INTEGER NOT NULL DEFAULT 0,
    content_purged INTEGER NOT NULL DEFAULT 0,
    source_ref TEXT,
    redeliver_after_lease_expiry INTEGER NOT NULL DEFAULT 1
);

CREATE INDEX IF NOT EXISTS printdock_jobs_claim ON printdock_jobs (agent_id, state, seq);
CREATE INDEX IF NOT EXISTS printdock_jobs_created ON printdock_jobs (created_at);
CREATE INDEX IF NOT EXISTS printdock_jobs_agent_created ON printdock_jobs (agent_id, created_at);
CREATE INDEX IF NOT EXISTS printdock_jobs_state ON printdock_jobs (state, seq);
CREATE INDEX IF NOT EXISTS printdock_jobs_finished ON printdock_jobs (finished_at);

-- Append-only history of every job state change, written in the same transaction as the change.
CREATE TABLE IF NOT EXISTS printdock_job_events (
    seq INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id TEXT NOT NULL,
    agent_id TEXT NOT NULL,
    at TEXT NOT NULL,
    event TEXT NOT NULL,
    state_after TEXT NOT NULL,
    attempt INTEGER NOT NULL,
    outcome TEXT,
    reason TEXT,
    detail TEXT,
    spool_job_id TEXT,
    late INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS printdock_job_events_job ON printdock_job_events (job_id, seq);

CREATE TABLE IF NOT EXISTS printdock_discovery (
    agent_id TEXT PRIMARY KEY,
    pending_request_id TEXT,
    requested_at TEXT,
    result TEXT,
    received_at TEXT
);
