<?php

declare(strict_types=1);

namespace PrintDock\Server\Port;

use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\DiscoveryReport;
use PrintDock\Server\Domain\StoredDiscovery;

/**
 * Open discovery requests and the latest result per agent (section 14). Implemented by the host.
 */
interface DiscoveryStore
{
    /** Opens a request for the agent, replacing one that is still open. */
    public function open(AgentId $agentId, string $requestId, \DateTimeImmutable $now): void;

    /** The id of the request still waiting for an answer, if any. */
    public function openRequestId(AgentId $agentId): ?string;

    /**
     * Stores the result as the agent's latest and, atomically, closes the open request when
     * `$report->requestId` is that request. Returns true only in that case ("matched"); a repeat of the same
     * result stores it again and returns false.
     */
    public function storeResult(AgentId $agentId, DiscoveryReport $report, \DateTimeImmutable $now): bool;

    public function latest(AgentId $agentId): ?StoredDiscovery;

    /** Forgets the agent's open request and latest result. Does nothing when there is none. */
    public function delete(AgentId $agentId): void;
}
