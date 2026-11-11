<?php

declare(strict_types=1);

namespace PrintDock\Server\Adapter\Pdo;

/**
 * Creates the tables of the PDO adapters from `resources/schema/<dialect>.sql` and upgrades a database created
 * by an earlier version. Idempotent: run it once per deployment (not on every request).
 *
 * `CREATE TABLE IF NOT EXISTS` cannot add columns to a table that exists, so {@see COLUMNS} and
 * {@see INDEXES} list what later versions added; missing ones are added with `ALTER TABLE` / `CREATE INDEX`.
 * The `CREATE INDEX` statements of the schema file run after the columns are added, so an index on a new column
 * can be declared there (SQLite).
 * Then the {@see BACKFILL} statements fill in rows that predate a column (content size, station name,
 * `reference.source_ref`). They touch only rows still unfilled and run on every install, so a run that failed
 * midway is completed by the next one. Printer names of old jobs stay null: the old printer configuration is not
 * reliable history.
 *
 * Two installs at once (two web workers after a deployment): on SQLite the whole install is one `BEGIN
 * IMMEDIATE` transaction and reads the columns inside it; MySQL commits every DDL statement on its own, so a
 * "duplicate column" or "duplicate key name" error from an `ALTER` another process just ran is taken as done.
 * A host with its own migration tool should copy the SQL into a migration instead of calling this at runtime.
 */
final class PdoSchema
{
    /** Columns added after the first version: table => name => [sqlite, mysql] definition. */
    private const COLUMNS = [
        'printdock_jobs' => [
            'agent_name' => ['TEXT', 'VARCHAR(255) NULL'],
            'printer_name' => ['TEXT', 'VARCHAR(255) NULL'],
            'content_size' => ['INTEGER NOT NULL DEFAULT 0', 'BIGINT NOT NULL DEFAULT 0'],
            'content_purged' => ['INTEGER NOT NULL DEFAULT 0', 'TINYINT(1) NOT NULL DEFAULT 0'],
            'source_ref' => ['TEXT', 'VARCHAR(255) NULL'],
            'redeliver_after_lease_expiry' => ['INTEGER NOT NULL DEFAULT 1', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ],
        'printdock_agents' => [
            'empty_claim_at' => ['TEXT', 'DATETIME(6) NULL'],
            'empty_claim_printers' => ['TEXT', 'TEXT NULL'],
            'pending_key_id' => ['TEXT', 'CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL'],
            'pending_key_hash' => ['TEXT', 'CHAR(64) CHARACTER SET ascii NULL'],
        ],
    ];

    /**
     * MySQL indexes added after the first version: table => name => `[UNIQUE ](columns)`. SQLite gets them from
     * the `CREATE INDEX IF NOT EXISTS` statements of its schema file.
     */
    private const INDEXES = [
        'printdock_jobs' => [
            'printdock_jobs_agent_created' => '(agent_id, created_at)',
            'printdock_jobs_state' => '(state, seq)',
            'printdock_jobs_finished' => '(finished_at)',
        ],
        'printdock_agents' => [
            'printdock_agents_pending_key_id' => 'UNIQUE (pending_key_id)',
        ],
    ];

    /**
     * How rows that existed before a column are filled in: [sqlite, mysql]. Each touches only unfilled rows, so
     * running it again changes nothing.
     */
    private const BACKFILL = [
        'agent_name' => [
            'UPDATE printdock_jobs SET agent_name = (SELECT a.name FROM printdock_agents a WHERE a.agent_id = printdock_jobs.agent_id)'
            . ' WHERE agent_name IS NULL AND agent_id IN (SELECT agent_id FROM printdock_agents)',
            'UPDATE printdock_jobs j JOIN printdock_agents a ON a.agent_id = j.agent_id SET j.agent_name = a.name'
            . ' WHERE j.agent_name IS NULL',
        ],
        'content_size' => [
            'UPDATE printdock_jobs SET content_size = LENGTH(CAST(content AS BLOB))'
            . ' WHERE content_size = 0 AND content_purged = 0 AND LENGTH(CAST(content AS BLOB)) > 0',
            "UPDATE printdock_jobs SET content_size = LENGTH(content) WHERE content_size = 0 AND content_purged = 0 AND content <> ''",
        ],
        'source_ref' => [
            "UPDATE printdock_jobs SET source_ref = SUBSTR(json_extract(reference, '$.source_ref'), 1, 255)"
            . " WHERE source_ref IS NULL AND reference IS NOT NULL AND json_valid(reference) AND json_type(reference, '$.source_ref') = 'text'",
            "UPDATE printdock_jobs SET source_ref = LEFT(JSON_UNQUOTE(JSON_EXTRACT(reference, '$.source_ref')), 255)"
            . " WHERE source_ref IS NULL AND reference IS NOT NULL AND JSON_VALID(reference) AND JSON_TYPE(JSON_EXTRACT(reference, '$.source_ref')) = 'STRING'",
        ],
    ];

    /** MySQL: duplicate column name, duplicate key name. */
    private const ALREADY_THERE = [1060, 1061];

    public static function install(\PDO $pdo): void
    {
        $db = new PdoSupport($pdo);
        $file = dirname(__DIR__, 3) . '/resources/schema/' . ($db->isSqlite() ? 'sqlite' : 'mysql') . '.sql';
        $sql = file_get_contents($file);
        if (false === $sql) {
            throw new \RuntimeException('Cannot read ' . $file);
        }
        $statements = array_filter(
            explode(";\n", (string) preg_replace('/^--.*$/m', '', $sql)),
            static fn (string $s): bool => '' !== trim($s),
        );
        $isIndex = static fn (string $s): bool => 1 === preg_match('/^\s*CREATE\s+(UNIQUE\s+)?INDEX\b/i', $s);
        $tables = array_filter($statements, static fn (string $s): bool => !$isIndex($s));
        $indexes = array_filter($statements, $isIndex);
        if ($db->isSqlite()) {
            $db->transactional(static function () use ($db, $tables, $indexes): void {
                self::create($db, $tables);
                self::upgrade($db, $indexes);
            });

            return;
        }
        self::create($db, $tables);
        self::upgrade($db, $indexes);
    }

    /**
     * @param array<string> $statements
     */
    private static function create(PdoSupport $db, array $statements): void
    {
        foreach ($statements as $statement) {
            $db->pdo->exec($statement);
        }
    }

    /**
     * @param array<string> $indexStatements the `CREATE INDEX` statements of the schema file
     */
    private static function upgrade(PdoSupport $db, array $indexStatements): void
    {
        $dialect = $db->isSqlite() ? 0 : 1;
        foreach (self::COLUMNS as $table => $columns) {
            $present = self::columns($db, $table);
            foreach ($columns as $column => $definitions) {
                if (!in_array($column, $present, true)) {
                    self::ddl($db, sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definitions[$dialect]));
                }
            }
        }
        self::create($db, $indexStatements);
        if (!$db->isSqlite()) {
            foreach (self::INDEXES as $table => $definitions) {
                $present = array_map(static fn (array $r): string => PdoSupport::string($r['name']), $db->fetchRows(
                    'SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$table],
                ));
                foreach ($definitions as $index => $definition) {
                    if (!in_array($index, $present, true)) {
                        $unique = str_starts_with($definition, 'UNIQUE ');
                        self::ddl($db, sprintf(
                            'CREATE %sINDEX %s ON %s %s',
                            $unique ? 'UNIQUE ' : '',
                            $index,
                            $table,
                            $unique ? substr($definition, 7) : $definition,
                        ));
                    }
                }
            }
        }
        foreach (self::BACKFILL as $statements) {
            $db->transactional(static fn () => $db->pdo->exec($statements[$dialect]));
        }
    }

    /** @return list<string> */
    private static function columns(PdoSupport $db, string $table): array
    {
        $rows = $db->isSqlite()
            ? $db->fetchRows(sprintf('PRAGMA table_info(%s)', $table))
            : $db->fetchRows('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);

        return array_map(static fn (array $r): string => PdoSupport::string($r['name']), $rows);
    }

    /** Runs an `ALTER` / `CREATE INDEX`; on MySQL, losing the race to a concurrent install counts as success. */
    private static function ddl(PdoSupport $db, string $sql): void
    {
        try {
            $db->pdo->exec($sql);
        } catch (\PDOException $e) {
            if ($db->isSqlite() || !in_array(PdoSupport::driverCode($e), self::ALREADY_THERE, true)) {
                throw $e;
            }
        }
    }
}
