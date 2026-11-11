<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Integration;

use PrintDock\Server\Adapter\InMemory\MutableClock;
use PrintDock\Server\Adapter\Pdo\PdoAgentRepository;
use PrintDock\Server\Adapter\Pdo\PdoDiscoveryStore;
use PrintDock\Server\Adapter\Pdo\PdoJobRepository;
use PrintDock\Server\Adapter\Pdo\PdoSchema;
use PrintDock\Server\Adapter\Pdo\PdoSupport;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobState;
use PrintDock\Server\Tests\Support\InterceptingPdo;
use PrintDock\Server\Tests\Support\Stack;

/**
 * Same rules on MySQL 8. Runs only when PRINTDOCK_TEST_MYSQL_DSN is set (`make test-mysql` does that).
 */
final class MysqlProtocolRulesTest extends ProtocolRulesTestCase
{
    use SchemaUpgradeTests;
    use PdoConcurrencyTests;

    /**
     * @param class-string<\PDO> $class
     */
    private function connect(string $class = \PDO::class): \PDO
    {
        $dsn = getenv('PRINTDOCK_TEST_MYSQL_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('PRINTDOCK_TEST_MYSQL_DSN is not set');
        }

        return new $class($dsn, getenv('PRINTDOCK_TEST_MYSQL_USER') ?: 'root', getenv('PRINTDOCK_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    protected function interceptedPair(): array
    {
        $mine = $this->connect(InterceptingPdo::class);
        \assert($mine instanceof InterceptingPdo);

        return [$mine, $this->emptyDatabase()];
    }

    protected function raceTrigger(): string
    {
        return 'ALTER TABLE';
    }

    public function test_a_claim_skips_a_queued_job_that_another_transaction_has_locked(): void
    {
        $agent = $this->stack->register();
        $first = $this->stack->enqueue($agent);
        $second = $this->stack->enqueue($agent);
        $connectionA = $this->connect();
        $connectionB = $this->connect();
        $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 2');

        $connectionA->beginTransaction();
        (new PdoJobRepository($connectionA))->modify($first->id(), static fn (Job $job): int => $job->attempt());
        $claim = (new PdoJobRepository($connectionB))->claimNext($agent->agent->id(), ['p1'], $this->stack->clock->now(), 10);
        $connectionA->rollBack();

        self::assertSame($second->id()->value, $claim?->job->id()->value, 'the locked row must be skipped, not waited for');
        self::assertSame(JobState::Queued, $this->stack->jobs->find($first->id())?->state());
    }

    protected function emptyDatabase(): \PDO
    {
        $pdo = $this->connect();
        foreach (['printdock_discovery', 'printdock_job_events', 'printdock_jobs', 'printdock_agents'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }

        return $pdo;
    }

    protected function jobIndexes(\PDO $pdo): array
    {
        return array_map(static fn (array $r): string => PdoSupport::string($r['name']), (new PdoSupport($pdo))->fetchRows(
            "SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'printdock_jobs'",
        ));
    }

    protected function agentIndexes(\PDO $pdo): array
    {
        return array_map(static fn (array $r): string => PdoSupport::string($r['name']), (new PdoSupport($pdo))->fetchRows(
            "SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'printdock_agents'",
        ));
    }

    protected function createStack(): Stack
    {
        $pdo = $this->emptyDatabase();
        PdoSchema::install($pdo);

        return new Stack(new MutableClock(), new PdoAgentRepository($pdo), new PdoJobRepository($pdo), new PdoDiscoveryStore($pdo));
    }
}
