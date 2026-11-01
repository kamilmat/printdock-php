<?php

declare(strict_types=1);

namespace PrintDock\Server\Port;

use PrintDock\Server\Domain\Agent;
use PrintDock\Server\Domain\AgentActivity;
use PrintDock\Server\Domain\AgentId;

/**
 * Persistence of agents. Implemented by the host.
 *
 * Authentication does one lookup by key id per request, so `findByKeyId` needs a unique index on it.
 * Hello and activity writes are separate, narrow operations on purpose: an agent sends hello, claims and acks
 * concurrently, and none of them may overwrite what an administrator changed through {@see save()}.
 */
interface AgentRepository
{
    /** Inserts a new agent or replaces the administrative fields (name, settings, printers, revocation) of one. */
    public function save(Agent $agent): void;

    public function find(AgentId $id): ?Agent;

    /** The 8-character id from `pa1_<key_id>_<secret>`. Must be an indexed, exact-match lookup. */
    public function findByKeyId(string $keyId): ?Agent;

    /** @return list<Agent> administrative listing, oldest first */
    public function all(): array;

    /**
     * Persists what {@see Agent::recordHello()} changed: last hello report, reported printers, hello counter and
     * timestamps, and clears `empty_claim_at`. Must not touch administrative fields.
     */
    public function recordHello(Agent $agent): void;

    /** Removes the agent row. Does nothing for an unknown id. Jobs and discovery data are the caller's business. */
    public function delete(AgentId $id): void;

    /** Marks the agent as seen now (and, for a claim, bumps `last_claim_at` and the claim counter). */
    public function recordActivity(AgentId $id, AgentActivity $activity, \DateTimeImmutable $now): void;

    /**
     * Persists {@see Agent::recordClaimResult()}: when the claim just answered came back empty without waiting and
     * the printers it offered, or null and none. Only those fields; called after a claim when they change.
     *
     * @param list<string> $printerIds
     */
    public function recordClaimResult(AgentId $id, ?\DateTimeImmutable $emptyAt, array $printerIds = []): void;
}
