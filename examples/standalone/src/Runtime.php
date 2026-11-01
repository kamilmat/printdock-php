<?php

declare(strict_types=1);

namespace PrintDock\Server\Examples;

use PrintDock\Server\Adapter\Pdo\PdoAgentRepository;
use PrintDock\Server\Adapter\Pdo\PdoDiscoveryStore;
use PrintDock\Server\Adapter\Pdo\PdoJobRepository;
use PrintDock\Server\Adapter\Pdo\PdoSchema;
use PrintDock\Server\Application\PapAdmin;
use PrintDock\Server\Application\PapServer;
use PrintDock\Server\Application\PapServerConfig;
use PrintDock\Server\Http\PapRouter;
use Psr\Clock\ClockInterface;

/**
 * Wires the library over SQLite: the three PDO adapters, the application services and the router.
 * This is the whole "integration" a host app has to write, minus its own repositories.
 */
final class Runtime
{
    public const PAP_PREFIX = '/pap/v1';

    public readonly PapServer $server;
    public readonly PapAdmin $admin;
    public readonly PapRouter $router;

    public function __construct(string $databaseFile, string $publicBaseUrl, bool $claimCooldown = true)
    {
        $pdo = new \PDO('sqlite:' . $databaseFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        PdoSchema::install($pdo);

        $clock = new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }
        };
        $agents = new PdoAgentRepository($pdo);
        $jobs = new PdoJobRepository($pdo);
        $discovery = new PdoDiscoveryStore($pdo);
        $config = new PapServerConfig(rtrim($publicBaseUrl, '/') . self::PAP_PREFIX, claimCooldown: $claimCooldown);

        $this->server = new PapServer($agents, $jobs, $discovery, $clock, $config);
        $this->admin = new PapAdmin($agents, $jobs, $discovery, $clock, $config);
        $this->router = new PapRouter($this->server, self::PAP_PREFIX, static function (\Throwable $e): void {
            error_log((string) $e);
        });
    }
}
