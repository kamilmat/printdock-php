<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Integration;

use PrintDock\Server\Adapter\InMemory\MutableClock;
use PrintDock\Server\Adapter\Pdo\PdoAgentRepository;
use PrintDock\Server\Adapter\Pdo\PdoDiscoveryStore;
use PrintDock\Server\Adapter\Pdo\PdoJobRepository;
use PrintDock\Server\Adapter\Pdo\PdoSchema;
use PrintDock\Server\Adapter\Pdo\PdoSupport;
use PrintDock\Server\Domain\HistoryQuery;
use PrintDock\Server\Domain\JobEvent;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobState;
use PrintDock\Server\Domain\JobSummary;
use PrintDock\Server\Tests\Support\InterceptingPdo;
use PrintDock\Server\Tests\Support\Stack;

/**
 * `PdoSchema::install()` on a database created by the previous version (`tests/Fixtures/schema-0.5`): columns,
 * the events table and indexes are added, existing rows are filled in and keep working.
 */
trait SchemaUpgradeTests
{
    /** A connection to a database without any printdock table. */
    abstract protected function emptyDatabase(): \PDO;

    /** @return list<string> */
    abstract protected function jobIndexes(\PDO $pdo): array;

    /**
     * Two connections to one database without any printdock table; the first records and intercepts its SQL.
     *
     * @return array{InterceptingPdo, \PDO}
     */
    abstract protected function interceptedPair(): array;

    /** The statement at which a concurrent install is slipped in (one of ours that runs before any change). */
    abstract protected function raceTrigger(): string;

    private function createPreviousSchema(\PDO $pdo): void
    {
        $old = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/schema-0.5/' . ((new PdoSupport($pdo))->isSqlite() ? 'sqlite' : 'mysql') . '.sql');
        foreach (explode(";\n", (string) preg_replace('/^--.*$/m', '', $old)) as $statement) {
            if ('' !== trim($statement)) {
                $pdo->exec($statement);
            }
        }
    }

    /** Writes a 0.5-shaped job row by hand (the adapter writes columns that version did not have). */
    private function insertPreviousJob(\PDO $pdo, string $agentId, string $id, string $state, ?string $finished, ?string $reference, string $content): void
    {
        (new PdoSupport($pdo))->run(
            'INSERT INTO printdock_jobs (job_id, agent_id, printer_id, printer_role, format, content, delivery, options, title,'
            . ' title_sensitive, reference, state, created_at, not_before, expires_at, attempt, lease_token, lease_tokens,'
            . ' lease_until, leased_at, last_contact_at, finished_at, outcome, reason, detail, spool_job_id, late)'
            . " VALUES (?, ?, 'p1', 'label', 'zpl', ?, 'inline', NULL, 'DPD 1', 0, ?, ?, '2026-10-30 12:00:00.000000',"
            . " '2026-10-30 12:00:00.000000', '2099-01-01 00:00:00.000000', 0, NULL, '[]', NULL, NULL, NULL, ?, NULL, NULL, NULL, NULL, 0)",
            [$id, $agentId, $content, $reference, $state, $finished],
            2,
        );
    }

    public function test_an_upgrade_that_failed_midway_is_completed_by_the_next_install(): void
    {
        [$pdo] = $this->interceptedPair();
        $this->createPreviousSchema($pdo);
        $agent = (new Stack(new MutableClock(), new PdoAgentRepository($pdo), new PdoJobRepository($pdo), new PdoDiscoveryStore($pdo)))->register();
        $id = JobId::generate()->value;
        $this->insertPreviousJob($pdo, $agent->agent->id()->value, $id, 'queued', null, '{"source_ref":"order-7"}', 'label bytes');
        $pdo->before = static function (string $sql): void {
            if (str_starts_with($sql, 'UPDATE printdock_jobs')) {
                throw new \RuntimeException('connection lost');
            }
        };
        try {
            PdoSchema::install($pdo);
            self::fail('the first install was meant to fail');
        } catch (\RuntimeException $e) {
            self::assertSame('connection lost', $e->getMessage());
        }
        $pdo->before = null;

        PdoSchema::install($pdo);

        $jobs = new PdoJobRepository($pdo);
        $job = $jobs->find(JobId::fromString($id));
        self::assertSame(11, $job?->contentSize());
        self::assertSame('Packing 1', $job->agentName());
        self::assertSame([$id], array_map(static fn (JobSummary $j): string => $j->id->value, $jobs->history(new HistoryQuery(text: 'order-7'))->jobs));
    }

    public function test_an_install_survives_another_install_running_at_the_same_time(): void
    {
        [$mine, $other] = $this->interceptedPair();
        $this->createPreviousSchema($other);
        $trigger = $this->raceTrigger();
        $raced = false;
        $mine->before = static function (string $sql) use (&$raced, $other, $trigger): void {
            if (!$raced && str_starts_with($sql, $trigger)) {
                $raced = true;
                PdoSchema::install($other);
            }
        };

        PdoSchema::install($mine);

        self::assertTrue($raced, 'the concurrent install must have run');
        $stack = new Stack(new MutableClock(), new PdoAgentRepository($mine), new PdoJobRepository($mine), new PdoDiscoveryStore($mine));
        $agent = $stack->register();
        self::assertSame(204, $stack->claim($agent)->status);
        self::assertNotNull($stack->admin->findAgent($agent->agent->id())?->emptyClaimAt());
    }

    public function test_install_upgrades_a_database_of_the_previous_version_and_keeps_its_data(): void
    {
        $pdo = $this->emptyDatabase();
        $this->createPreviousSchema($pdo);
        // the agents table did not change but for a new column: register through the adapter
        $agents = new PdoAgentRepository($pdo);
        $stack = new Stack(new MutableClock(), $agents, new PdoJobRepository($pdo), new PdoDiscoveryStore($pdo));
        $agent = $stack->register();
        $content = "\x00\xffbinary\u{0105}";
        $queued = JobId::generate()->value;
        $printed = JobId::generate()->value;
        $this->insertPreviousJob($pdo, $agent->agent->id()->value, $queued, 'queued', null, '{"source_ref":"order-1"}', $content);
        $this->insertPreviousJob($pdo, $agent->agent->id()->value, $printed, 'printed', '2026-10-30 13:00:00.000000', null, $content);

        PdoSchema::install($pdo);
        PdoSchema::install($pdo);

        $jobs = new PdoJobRepository($pdo);
        $job = $jobs->find(JobId::fromString($queued));
        self::assertNotNull($job);
        self::assertSame($content, $job->content());
        self::assertSame(strlen($content), $job->contentSize());
        self::assertSame('Packing 1', $job->agentName());
        self::assertNull($job->printerName());
        self::assertFalse($job->contentPurged());
        self::assertTrue($job->redeliversAfterLeaseExpiry(), 'jobs from before the column keep redelivery');
        self::assertSame([$queued], array_map(static fn (JobSummary $j): string => $j->id->value, $jobs->history(new HistoryQuery(text: 'order-1'))->jobs));
        self::assertSame([], $jobs->events(JobId::fromString($queued)), 'old jobs have no recorded history');
        foreach (['printdock_jobs_agent_created', 'printdock_jobs_state', 'printdock_jobs_finished'] as $index) {
            self::assertContains($index, $this->jobIndexes($pdo));
        }

        $upgraded = new Stack(new MutableClock(), $agents, $jobs, new PdoDiscoveryStore($pdo));
        $upgraded->admin->cancel(JobId::fromString($queued));
        self::assertSame(['cancelled'], array_map(static fn (JobEvent $e): string => $e->type->value, $upgraded->admin->jobEvents(JobId::fromString($queued))));
        self::assertSame(1, $upgraded->admin->purgeContent(new \DateTimeImmutable('2026-10-30T13:30:00Z')));
        self::assertTrue($jobs->find(JobId::fromString($printed))?->contentPurged());
        self::assertSame(JobState::Cancelled, $jobs->find(JobId::fromString($queued))?->state());
    }
}
