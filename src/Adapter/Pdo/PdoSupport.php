<?php

declare(strict_types=1);

namespace PrintDock\Server\Adapter\Pdo;

/**
 * What the three PDO adapters share: dialect detection, transactions, and the time and JSON encodings of the
 * schema. SQLite and MySQL 8+ / MariaDB 10.6+ are supported.
 *
 * Transactions are joined, not nested: when the host has a transaction open the work simply runs inside it.
 * SQLite starts `BEGIN IMMEDIATE` so a reader never has to upgrade to a writer and hit SQLITE_BUSY mid-way.
 * A transaction of our own that MySQL aborts with a deadlock (1213) or a lock wait timeout (1205) is rolled back
 * and run once more, so `$work` must be safe to repeat (load, decide, write); a joined one is the host's to retry.
 */
final class PdoSupport
{
    private const TIME_FORMAT = 'Y-m-d H:i:s.u';

    /** MySQL errors after which the whole transaction may simply be run again. */
    private const RETRYABLE = [1205, 1213];

    private readonly bool $sqlite;

    private bool $ownTransaction = false;

    public function __construct(public readonly \PDO $pdo)
    {
        if (\PDO::ERRMODE_EXCEPTION !== $pdo->getAttribute(\PDO::ATTR_ERRMODE)) {
            throw new \InvalidArgumentException('The PDO connection must use PDO::ERRMODE_EXCEPTION');
        }
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported PDO driver "%s" (sqlite and mysql are)', is_string($driver) ? $driver : '?'));
        }
        $this->sqlite = 'sqlite' === $driver;
    }

    public function isSqlite(): bool
    {
        return $this->sqlite;
    }

    /** `FOR UPDATE`, optionally with `SKIP LOCKED`; empty on SQLite, which locks the whole database instead. */
    public function lockClause(bool $skipLocked = false): string
    {
        if ($this->sqlite) {
            return '';
        }

        return $skipLocked ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE';
    }

    public function insertIgnore(): string
    {
        return $this->sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        if ($this->ownTransaction || $this->pdo->inTransaction()) {
            return $work();
        }

        try {
            return $this->runOwnTransaction($work);
        } catch (\PDOException $e) {
            if (!self::isRetryable($e)) {
                throw $e;
            }

            return $this->runOwnTransaction($work);
        }
    }

    /** A deadlock or lock wait timeout: the transaction lost a race and can be run again. */
    public static function isRetryable(\Throwable $e): bool
    {
        return $e instanceof \PDOException && in_array(self::driverCode($e), self::RETRYABLE, true);
    }

    /** The driver's own error number (MySQL 1060, 1213, ...), when the exception carries one. */
    public static function driverCode(\PDOException $e): ?int
    {
        $code = $e->errorInfo[1] ?? null;

        return is_numeric($code) ? (int) $code : null;
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function runOwnTransaction(callable $work): mixed
    {
        $this->sqlite ? $this->pdo->exec('BEGIN IMMEDIATE') : $this->pdo->beginTransaction();
        $this->ownTransaction = true;

        try {
            $result = $work();
            $this->sqlite ? $this->pdo->exec('COMMIT') : $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->sqlite) {
                // PDO before PHP 8.4 does not see a transaction opened with exec('BEGIN'), so inTransaction() is no guide.
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (\PDOException) {
                    // SQLite already rolled back (e.g. SQLITE_FULL) - nothing left to undo.
                }
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        } finally {
            $this->ownTransaction = false;
        }
    }

    /**
     * @param array<int|string, scalar|null> $params
     */
    public function run(string $sql, array $params = [], int|string|null $lobParam = null): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $key = is_int($name) ? $name + 1 : $name;
            if ($name === $lobParam) {
                $statement->bindValue($key, $value, \PDO::PARAM_LOB);
            } elseif (null === $value) {
                $statement->bindValue($key, null, \PDO::PARAM_NULL);
            } elseif (is_int($value) || is_bool($value)) {
                $statement->bindValue($key, (int) $value, \PDO::PARAM_INT);
            } else {
                $statement->bindValue($key, (string) $value);
            }
        }
        $statement->execute();

        return $statement;
    }

    /**
     * @param array<int|string, scalar|null> $params
     *
     * @return array<string, mixed>|null
     */
    public function fetchRow(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        /* @var array<string, mixed>|false $row */
        return false === $row ? null : $row;
    }

    /**
     * @param array<int|string, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public function fetchRows(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    public static function formatTime(?\DateTimeInterface $time): ?string
    {
        if (null === $time) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }

    public static function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException('A timestamp column did not come back as a string');
        }
        $time = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, str_contains($value, '.') ? $value : $value . '.000000', new \DateTimeZone('UTC'));
        if (false === $time) {
            throw new \UnexpectedValueException(sprintf('Unreadable timestamp "%s"', $value));
        }

        return $time;
    }

    public static function requiredTime(mixed $value): \DateTimeImmutable
    {
        return self::parseTime($value) ?? throw new \UnexpectedValueException('A required timestamp column is empty');
    }

    public static function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<mixed>
     */
    public static function decodeJson(mixed $value): array
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('A JSON column did not come back as a string');
        }
        $data = json_decode($value, true, 64, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : throw new \UnexpectedValueException('Expected a string column'));
    }

    public static function stringOrNull(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }

    /** At most `$bytes` bytes, never cutting a UTF-8 sequence in half. */
    public static function truncateUtf8(string $value, int $bytes): string
    {
        $value = substr($value, 0, $bytes);
        while ('' !== $value && 1 !== preg_match('//u', $value)) {
            $value = substr($value, 0, -1);
        }

        return $value;
    }

    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('Expected an integer column');
    }
}
