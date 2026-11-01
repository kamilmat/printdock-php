<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Support;

use PrintDock\Server\Domain\Agent;
use PrintDock\Server\Domain\AgentActivity;
use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Port\AgentRepository;

/**
 * Delegates to another repository, but `find()` can be made to return an earlier snapshot: what a request that
 * read the agent just before a concurrent write would see.
 */
final class StaleReadAgentRepository implements AgentRepository
{
    public ?Agent $stale = null;

    public function __construct(private readonly AgentRepository $inner)
    {
    }

    public function save(Agent $agent): void
    {
        $this->inner->save($agent);
    }

    public function find(AgentId $id): ?Agent
    {
        return null !== $this->stale && $this->stale->id()->equals($id) ? clone $this->stale : $this->inner->find($id);
    }

    public function findByKeyId(string $keyId): ?Agent
    {
        return $this->inner->findByKeyId($keyId);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function recordHello(Agent $agent): void
    {
        $this->inner->recordHello($agent);
    }

    public function delete(AgentId $id): void
    {
        $this->inner->delete($id);
    }

    public function recordActivity(AgentId $id, AgentActivity $activity, \DateTimeImmutable $now): void
    {
        $this->inner->recordActivity($id, $activity, $now);
    }

    public function recordClaimResult(AgentId $id, ?\DateTimeImmutable $emptyAt, array $printerIds = []): void
    {
        $this->inner->recordClaimResult($id, $emptyAt, $printerIds);
    }
}
