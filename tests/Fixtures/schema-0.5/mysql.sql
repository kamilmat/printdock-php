-- PrintDock PAP/1 server schema, MySQL 8+ / MariaDB 10.6+ dialect (InnoDB; claiming relies on
-- SELECT ... FOR UPDATE SKIP LOCKED). Timestamps are UTC DATETIME(6): store and compare in UTC only.
-- Applied by PrintDock\Server\Adapter\Pdo\PdoSchema::install().

CREATE TABLE IF NOT EXISTS printdock_agents (
    agent_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    key_id CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    key_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    poll_interval_s INT NOT NULL,
    hello_interval_s INT NOT NULL,
    lease_s INT NOT NULL,
    spool_timeout_s INT NOT NULL,
    printers_managed_by VARCHAR(8) NOT NULL,
    printers LONGTEXT NOT NULL,
    reported_printers LONGTEXT NOT NULL,
    config_version VARCHAR(40) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    last_hello_at DATETIME(6) NULL,
    last_claim_at DATETIME(6) NULL,
    last_seen_at DATETIME(6) NULL,
    hello_count INT NOT NULL DEFAULT 0,
    claim_count INT NOT NULL DEFAULT 0,
    last_hello LONGTEXT NULL,
    UNIQUE KEY printdock_agents_key_id (key_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS printdock_jobs (
    seq BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    agent_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    printer_id VARCHAR(128) NOT NULL,
    printer_role VARCHAR(128) NULL,
    format VARCHAR(8) NOT NULL,
    content LONGBLOB NOT NULL,
    delivery VARCHAR(8) NOT NULL,
    options TEXT NULL,
    title VARCHAR(1000) NULL,
    title_sensitive TINYINT(1) NOT NULL,
    reference TEXT NULL,
    state VARCHAR(16) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    not_before DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    attempt INT NOT NULL,
    lease_token VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_tokens TEXT NOT NULL,
    lease_until DATETIME(6) NULL,
    leased_at DATETIME(6) NULL,
    last_contact_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    outcome VARCHAR(16) NULL,
    reason VARCHAR(40) NULL,
    detail TEXT NULL,
    spool_job_id VARCHAR(128) NULL,
    late TINYINT(1) NOT NULL,
    UNIQUE KEY printdock_jobs_job_id (job_id),
    KEY printdock_jobs_claim (agent_id, state, seq),
    KEY printdock_jobs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS printdock_discovery (
    agent_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    pending_request_id VARCHAR(128) NULL,
    requested_at DATETIME(6) NULL,
    result LONGTEXT NULL,
    received_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
