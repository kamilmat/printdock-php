<?php

declare(strict_types=1);

namespace PrintDock\Server\Adapter\InMemory;

use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\DiscoveryReport;
use PrintDock\Server\Domain\StoredDiscovery;
use PrintDock\Server\Port\DiscoveryStore;

/**
 * Discovery requests and results in PHP arrays. Single-process only.
 */
final class InMemoryDiscoveryStore implements DiscoveryStore
{
    /** @var array<string, string> */
    private array $open = [];

    /** @var array<string, StoredDiscovery> */
    private array $latest = [];

    public function open(AgentId $agentId, string $requestId, \DateTimeImmutable $now): void
    {
        $this->open[$agentId->value] = $requestId;
    }

    public function openRequestId(AgentId $agentId): ?string
    {
        return $this->open[$agentId->value] ?? null;
    }

    public function storeResult(AgentId $agentId, DiscoveryReport $report, \DateTimeImmutable $now): bool
    {
        $this->latest[$agentId->value] = new StoredDiscovery($now, $report->payload);
        $open = $this->open[$agentId->value] ?? null;
        if (null !== $open && $open === $report->requestId) {
            unset($this->open[$agentId->value]);

            return true;
        }

        return false;
    }

    public function latest(AgentId $agentId): ?StoredDiscovery
    {
        return $this->latest[$agentId->value] ?? null;
    }

    public function delete(AgentId $agentId): void
    {
        unset($this->open[$agentId->value], $this->latest[$agentId->value]);
    }
}
