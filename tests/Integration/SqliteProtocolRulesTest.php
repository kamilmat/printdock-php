<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Integration;

use PrintDock\Server\Adapter\InMemory\MutableClock;
use PrintDock\Server\Adapter\Pdo\PdoAgentRepository;
use PrintDock\Server\Adapter\Pdo\PdoDiscoveryStore;
use PrintDock\Server\Adapter\Pdo\PdoJobRepository;
use PrintDock\Server\Adapter\Pdo\PdoSchema;
use PrintDock\Server\Adapter\Pdo\PdoSupport;
use PrintDock\Server\Http\PapRouter;
use PrintDock\Server\Tests\Support\InterceptingPdo;
use PrintDock\Server\Tests\Support\Stack;

final class SqliteProtocolRulesTest extends ProtocolRulesTestCase
{
    use SchemaUpgradeTests;
    use PdoConcurrencyTests;

    /** @var list<string> database files to remove after the test */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    protected function interceptedPair(): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'printdock');
        $this->files[] = $file;
        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 2];

        return [new InterceptingPdo('sqlite:' . $file, options: $options), new \PDO('sqlite:' . $file, options: $options)];
    }

    protected function raceTrigger(): string
    {
        return 'BEGIN IMMEDIATE';
    }

    public function test_ack_extend_cancel_and_sweep_never_read_the_content(): void
    {
        $pdo = new InterceptingPdo('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        PdoSchema::install($pdo);
        $stack = new Stack(new MutableClock(), new PdoAgentRepository($pdo), new PdoJobRepository($pdo), new PdoDiscoveryStore($pdo));
        $agent = $stack->register(['lease_s' => 10, 'spool_timeout_s' => 20]);
        $stack->enqueue($agent, content: str_repeat('X', 100_000));
        $cancelled = $stack->enqueue($agent);
        $claimed = $stack->claimJob($agent);
        $pdo->statements = [];

        self::assertSame(200, $stack->extend($agent, $claimed->id(), $claimed->token())->status);
        self::assertSame(200, $stack->ack($agent, $claimed->id(), $claimed->token(), 'spooled')->status);
        $stack->admin->cancel($cancelled->id());
        $stack->clock->advance(60);
        $stack->jobs->sweep($agent->agent->id(), $stack->clock->now(), 30);
        $stack->admin->findSummary($cancelled->id());

        $reading = array_filter($pdo->statements, static fn (string $sql): bool => str_contains($sql, 'FROM printdock_jobs') && 1 === preg_match('/^\s*SELECT\b(?:(?!\bFROM\b).)*(\*|\bcontent\b)/is', $sql));
        self::assertCount(0, $reading, implode("\n", $reading));
        self::assertNotEmpty($pdo->statements);
    }

    public function test_stored_data_that_no_longer_reads_is_a_server_error_not_the_agents_bad_request(): void
    {
        $pdo = $this->emptyDatabase();
        PdoSchema::install($pdo);
        $stack = new Stack(new MutableClock(), new PdoAgentRepository($pdo), new PdoJobRepository($pdo), new PdoDiscoveryStore($pdo));
        $agent = $stack->register();
        $pdo->exec('UPDATE printdock_agents SET printers = \'[{"printer_id":"p1","role":"label","language":"zpl","dpi":203,"media":"x","rotate":45}]\'');
        $errors = [];
        $router = new PapRouter($stack->server, Stack::PREFIX, static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $response = $router->handle('POST', Stack::PREFIX . '/jobs/claim', ['X-PAP-Version' => '1', 'Authorization' => 'Bearer ' . $agent->key], '{"printer_ids":["p1"]}');

        self::assertSame(500, $response->status);
        self::assertSame('server_error', $response->decodedBody()['error']);
        self::assertStringNotContainsString('rotate', $response->body);
        self::assertCount(1, $errors);
        self::assertInstanceOf(\InvalidArgumentException::class, $errors[0]);
    }

    public function test_a_transaction_that_hits_a_deadlock_is_run_once_more(): void
    {
        $db = new PdoSupport($this->emptyDatabase());
        $runs = 0;
        $deadlock = static function (): \PDOException {
            $e = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
            $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

            return $e;
        };

        $result = $db->transactional(static function () use (&$runs, $deadlock): string {
            if (1 === ++$runs) {
                throw $deadlock();
            }

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(2, $runs);

        $runs = 0;
        $code = null;
        try {
            $db->transactional(static function () use (&$runs, $deadlock): void {
                ++$runs;
                throw $deadlock();
            });
        } catch (\PDOException $e) {
            $code = PdoSupport::driverCode($e);
        }
        self::assertSame(1213, $code, 'a second deadlock must propagate');
        self::assertSame(2, $runs, 'retried once, not forever');
        self::assertFalse($db->pdo->inTransaction());
    }

    protected function emptyDatabase(): \PDO
    {
        return new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    protected function jobIndexes(\PDO $pdo): array
    {
        return array_map(
            static fn (array $r): string => PdoSupport::string($r['name']),
            (new PdoSupport($pdo))->fetchRows("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'printdock_jobs'"),
        );
    }

    protected function agentIndexes(\PDO $pdo): array
    {
        return array_map(
            static fn (array $r): string => PdoSupport::string($r['name']),
            (new PdoSupport($pdo))->fetchRows("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'printdock_agents'"),
        );
    }

    protected function createStack(): Stack
    {
        $pdo = $this->emptyDatabase();
        PdoSchema::install($pdo);

        return new Stack(new MutableClock(), new PdoAgentRepository($pdo), new PdoJobRepository($pdo), new PdoDiscoveryStore($pdo));
    }
}
