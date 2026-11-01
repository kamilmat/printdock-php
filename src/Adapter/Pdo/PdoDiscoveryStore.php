<?php

declare(strict_types=1);

namespace PrintDock\Server\Adapter\Pdo;

use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\DiscoveryReport;
use PrintDock\Server\Domain\StoredDiscovery;
use PrintDock\Server\Port\DiscoveryStore;

/**
 * Discovery requests and latest results in `printdock_discovery`, one row per agent. Storing a result locks the
 * row so a request is closed by exactly one result ("matched" is true once).
 */
final class PdoDiscoveryStore implements DiscoveryStore
{
    private readonly PdoSupport $db;

    public function __construct(\PDO $pdo)
    {
        $this->db = new PdoSupport($pdo);
    }

    public function open(AgentId $agentId, string $requestId, \DateTimeImmutable $now): void
    {
        $this->db->transactional(function () use ($agentId, $requestId, $now): void {
            $this->ensureRow($agentId);
            $this->db->run(
                'UPDATE printdock_discovery SET pending_request_id = ?, requested_at = ? WHERE agent_id = ?',
                [$requestId, PdoSupport::formatTime($now), $agentId->value],
            );
        });
    }

    public function openRequestId(AgentId $agentId): ?string
    {
        $row = $this->db->fetchRow('SELECT pending_request_id FROM printdock_discovery WHERE agent_id = ?', [$agentId->value]);

        return null === $row ? null : PdoSupport::stringOrNull($row['pending_request_id']);
    }

    public function storeResult(AgentId $agentId, DiscoveryReport $report, \DateTimeImmutable $now): bool
    {
        return $this->db->transactional(function () use ($agentId, $report, $now): bool {
            $this->ensureRow($agentId);
            $row = $this->db->fetchRow(
                'SELECT pending_request_id FROM printdock_discovery WHERE agent_id = ?' . $this->db->lockClause(),
                [$agentId->value],
            );
            $pending = null === $row ? null : PdoSupport::stringOrNull($row['pending_request_id']);
            $matched = null !== $pending && $pending === $report->requestId;
            $this->db->run(
                'UPDATE printdock_discovery SET result = ?, received_at = ?, pending_request_id = ? WHERE agent_id = ?',
                [
                    PdoSupport::encodeJson($report->payload),
                    PdoSupport::formatTime($now),
                    $matched ? null : $pending,
                    $agentId->value,
                ],
            );

            return $matched;
        });
    }

    public function latest(AgentId $agentId): ?StoredDiscovery
    {
        $row = $this->db->fetchRow('SELECT result, received_at FROM printdock_discovery WHERE agent_id = ?', [$agentId->value]);
        if (null === $row || null === $row['result']) {
            return null;
        }
        /** @var array<string, mixed> $result */
        $result = PdoSupport::decodeJson($row['result']);

        return new StoredDiscovery(PdoSupport::requiredTime($row['received_at']), $result);
    }

    public function delete(AgentId $agentId): void
    {
        $this->db->run('DELETE FROM printdock_discovery WHERE agent_id = ?', [$agentId->value]);
    }

    private function ensureRow(AgentId $agentId): void
    {
        $this->db->run($this->db->insertIgnore() . ' INTO printdock_discovery (agent_id) VALUES (?)', [$agentId->value]);
    }
}
