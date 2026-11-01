<?php

declare(strict_types=1);

namespace PrintDock\Server\Adapter\Pdo;

/**
 * Creates the tables of the PDO adapters from `resources/schema/<dialect>.sql` and upgrades a database created
 * by an earlier version. Idempotent: run it once per deployment (not on every request).
 *
 * `CREATE TABLE IF NOT EXISTS` cannot add columns to a table that exists, so {@see COLUMNS} and
 * {@see INDEXES} list what later versions added; missing ones are added with `ALTER TABLE` / `CREATE INDEX`.
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
        ],
    ];

    /** Indexes of `printdock_jobs` added after the first version (SQLite gets them from the schema file). */
    private const INDEXES = [
        'printdock_jobs_agent_created' => '(agent_id, created_at)',
        'printdock_jobs_state' => '(state, seq)',
        'printdock_jobs_finished' => '(finished_at)',
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
        if ($db->isSqlite()) {
            $db->transactional(static function () use ($db, $statements): void {
                self::create($db, $statements);
                self::upgrade($db);
            });

            return;
        }
        self::create($db, $statements);
        self::upgrade($db);
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

    private static function upgrade(PdoSupport $db): void
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
        if (!$db->isSqlite()) {
            $indexes = array_map(static fn (array $r): string => PdoSupport::string($r['name']), $db->fetchRows(
                'SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                ['printdock_jobs'],
            ));
            foreach (self::INDEXES as $index => $columns) {
                if (!in_array($index, $indexes, true)) {
                    self::ddl($db, sprintf('CREATE INDEX %s ON printdock_jobs %s', $index, $columns));
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
