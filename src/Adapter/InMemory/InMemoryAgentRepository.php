<?php

declare(strict_types=1);

namespace PrintDock\Server\Adapter\InMemory;

use PrintDock\Server\Domain\Agent;
use PrintDock\Server\Domain\AgentActivity;
use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Port\AgentRepository;

/**
 * Agents in a PHP array. Stores copies, like a database would, so a mutated entity is not persisted until saved.
 * Single-process only: for tests, examples and as a readable reference of the port contract.
 */
final class InMemoryAgentRepository implements AgentRepository
{
    /** @var array<string, Agent> */
    private array $agents = [];

    public function save(Agent $agent): void
    {
        $existing = $this->agents[$agent->id()->value] ?? null;
        $copy = clone $agent;
        if (null !== $existing) {
            // Administrative save: keep what hello and activity wrote.
            $data = $existing->toData();
            $admin = $agent->toData();
            $copy = Agent::fromData([
                'name' => $admin['name'], 'revoked' => $admin['revoked'], 'settings' => $admin['settings'],
                'printers' => $admin['printers'], 'config_version' => $admin['config_version'],
            ] + $data);
        }
        $this->agents[$agent->id()->value] = $copy;
    }

    public function find(AgentId $id): ?Agent
    {
        $agent = $this->agents[$id->value] ?? null;

        return null === $agent ? null : clone $agent;
    }

    public function findByKeyId(string $keyId): ?Agent
    {
        foreach ($this->agents as $agent) {
            $data = $agent->toData();
            if ($data['key_id'] === $keyId || ($data['pending_key_id'] ?? null) === $keyId) {
                return clone $agent;
            }
        }

        return null;
    }

    public function setPendingKey(AgentId $id, string $keyId, string $keyHash): void
    {
        $stored = $this->agents[$id->value] ?? null;
        if (null !== $stored) {
            $this->agents[$id->value] = Agent::fromData(['pending_key_id' => $keyId, 'pending_key_hash' => $keyHash] + $stored->toData());
        }
    }

    public function promotePendingKey(AgentId $id, string $keyId): void
    {
        $stored = $this->agents[$id->value] ?? null;
        $data = $stored?->toData();
        if (null === $data || $keyId !== ($data['pending_key_id'] ?? null) || null === ($data['pending_key_hash'] ?? null)) {
            return;
        }
        $this->agents[$id->value] = Agent::fromData([
            'key_id' => $keyId, 'key_hash' => $data['pending_key_hash'], 'pending_key_id' => null, 'pending_key_hash' => null,
        ] + $data);
    }

    public function all(): array
    {
        return array_values(array_map(static fn (Agent $a): Agent => clone $a, $this->agents));
    }

    public function delete(AgentId $id): void
    {
        unset($this->agents[$id->value]);
    }

    public function recordHello(Agent $agent): void
    {
        $stored = $this->agents[$agent->id()->value] ?? null;
        if (null === $stored) {
            return;
        }
        $hello = $agent->toData();
        $this->agents[$agent->id()->value] = Agent::fromData([
            'reported_printers' => $hello['reported_printers'], 'last_hello' => $hello['last_hello'],
            'last_hello_at' => $hello['last_hello_at'], 'last_seen_at' => $hello['last_seen_at'],
            'hello_count' => $hello['hello_count'], 'empty_claim_at' => null, 'empty_claim_printers' => [],
        ] + $stored->toData());
    }

    public function recordActivity(AgentId $id, AgentActivity $activity, \DateTimeImmutable $now): void
    {
        $agent = $this->agents[$id->value] ?? null;
        $agent?->recordActivity($activity, $now);
    }

    public function recordClaimResult(AgentId $id, ?\DateTimeImmutable $emptyAt, array $printerIds = []): void
    {
        $agent = $this->agents[$id->value] ?? null;
        $agent?->recordClaimResult($emptyAt, $printerIds);
    }
}
