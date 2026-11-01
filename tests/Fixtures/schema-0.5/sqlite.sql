-- PrintDock PAP/1 server schema, SQLite dialect. Timestamps are UTC text 'YYYY-MM-DD HH:MM:SS.uuuuuu'
-- (lexicographic order = chronological order). Applied by PrintDock\Server\Adapter\Pdo\PdoSchema::install().

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
    last_hello TEXT
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
    late INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS printdock_jobs_claim ON printdock_jobs (agent_id, state, seq);
CREATE INDEX IF NOT EXISTS printdock_jobs_created ON printdock_jobs (created_at);

CREATE TABLE IF NOT EXISTS printdock_discovery (
    agent_id TEXT PRIMARY KEY,
    pending_request_id TEXT,
    requested_at TEXT,
    result TEXT,
    received_at TEXT
);
