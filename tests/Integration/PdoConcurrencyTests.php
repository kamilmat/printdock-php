<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Integration;

use PrintDock\Server\Adapter\InMemory\MutableClock;
use PrintDock\Server\Adapter\Pdo\PdoAgentRepository;
use PrintDock\Server\Adapter\Pdo\PdoDiscoveryStore;
use PrintDock\Server\Adapter\Pdo\PdoJobRepository;
use PrintDock\Server\Adapter\Pdo\PdoSchema;
use PrintDock\Server\Adapter\Pdo\PdoSupport;
use PrintDock\Server\Domain\JobEvent;
use PrintDock\Server\Tests\Support\InterceptingPdo;
use PrintDock\Server\Tests\Support\Stack;

/**
 * Races of the PDO adapters, staged by slipping a concurrent change in between two statements of an operation.
 */
trait PdoConcurrencyTests
{
    /**
     * @return array{InterceptingPdo, \PDO}
     */
    abstract protected function interceptedPair(): array;

    public function test_a_job_closed_while_history_is_purged_is_kept_with_its_events(): void
    {
        [$pdo] = $this->interceptedPair();
        PdoSchema::install($pdo);
        $stack = new Stack(new MutableClock(), new PdoAgentRepository($pdo), new PdoJobRepository($pdo), new PdoDiscoveryStore($pdo));
        $agent = $stack->register();
        $old = $stack->enqueue($agent);
        $stack->admin->cancel($old->id());
        $racing = $stack->enqueue($agent);
        $fired = false;
        $pdo->before = static function (string $sql) use (&$fired, $stack, $racing): void {
            if (!$fired && str_starts_with($sql, 'DELETE FROM printdock_job_events')) {
                $fired = true;
                // closed between the purge's choice of jobs and its deletes, at the very cut-off time
                $stack->admin->cancel($racing->id());
            }
        };

        $deleted = $stack->jobs->purgeHistory($stack->clock->now());
        $pdo->before = null;

        self::assertTrue($fired);
        self::assertSame(1, $deleted);
        self::assertNull($stack->jobs->find($old->id()));
        self::assertSame(['enqueued', 'cancelled'], array_map(static fn (JobEvent $e): string => $e->type->value, $stack->admin->jobEvents($racing->id())));
        $orphans = (new PdoSupport($pdo))->fetchRow(
            'SELECT COUNT(*) AS n FROM printdock_job_events e WHERE NOT EXISTS (SELECT 1 FROM printdock_jobs j WHERE j.job_id = e.job_id)',
        );
        self::assertSame(0, PdoSupport::int($orphans['n'] ?? null));
    }

    public function test_each_purge_batch_is_committed_before_the_next_one_starts(): void
    {
        [$pdo, $observer] = $this->interceptedPair();
        PdoSchema::install($pdo);
        $stack = new Stack(new MutableClock(), new PdoAgentRepository($pdo), new PdoJobRepository($pdo), new PdoDiscoveryStore($pdo));
        $agent = $stack->register();
        $total = PdoJobRepository::PURGE_BATCH + 1;
        for ($i = 0; $i < $total; ++$i) {
            $stack->admin->cancel($stack->enqueue($agent)->id());
        }
        $selects = 0;
        $seenByOthers = null;
        $pdo->before = static function (string $sql) use (&$selects, &$seenByOthers, $observer): void {
            if (str_starts_with($sql, 'SELECT job_id FROM printdock_jobs') && 2 === ++$selects) {
                $seenByOthers = (new PdoSupport($observer))->fetchRow('SELECT COUNT(*) AS n FROM printdock_jobs')['n'] ?? null;
            }
        };

        $deleted = $stack->jobs->purgeHistory($stack->clock->now());
        $pdo->before = null;

        self::assertSame($total, $deleted);
        self::assertSame(1, PdoSupport::int($seenByOthers), 'another connection must see the first batch deleted before the second begins');
    }
}
