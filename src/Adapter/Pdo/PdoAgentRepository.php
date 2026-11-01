<?php

declare(strict_types=1);

namespace PrintDock\Server\Adapter\Pdo;

use PrintDock\Server\Domain\Agent;
use PrintDock\Server\Domain\AgentActivity;
use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\AgentSettings;
use PrintDock\Server\Domain\HelloReport;
use PrintDock\Server\Domain\ManagedBy;
use PrintDock\Server\Domain\Printer;
use PrintDock\Server\Port\AgentRepository;

/**
 * Agents in `printdock_agents` (SQLite or MySQL). `save` writes only administrative columns of an existing row,
 * `recordHello`, `recordActivity` and `recordClaimResult` only theirs, so concurrent requests of one agent cannot undo each other.
 */
final class PdoAgentRepository implements AgentRepository
{
    private readonly PdoSupport $db;

    public function __construct(\PDO $pdo)
    {
        $this->db = new PdoSupport($pdo);
    }

    public function save(Agent $agent): void
    {
        $d = $agent->toData();
        $s = $d['settings'];
        $admin = [
            'name' => $d['name'], 'revoked' => $d['revoked'], 'poll_interval_s' => $s->pollIntervalS,
            'hello_interval_s' => $s->helloIntervalS, 'lease_s' => $s->leaseS, 'spool_timeout_s' => $s->spoolTimeoutS,
            'printers_managed_by' => $s->managedBy->value, 'printers' => $this->encodePrinters($d['printers']),
            'config_version' => $d['config_version'], 'id' => $d['id'],
        ];
        if (null !== $this->db->fetchRow('SELECT 1 AS present FROM printdock_agents WHERE agent_id = ?', [$d['id']])) {
            $this->db->run(
                'UPDATE printdock_agents SET name = :name, revoked = :revoked, poll_interval_s = :poll_interval_s,'
                . ' hello_interval_s = :hello_interval_s, lease_s = :lease_s, spool_timeout_s = :spool_timeout_s,'
                . ' printers_managed_by = :printers_managed_by, printers = :printers, config_version = :config_version'
                . ' WHERE agent_id = :id',
                $admin,
            );

            return;
        }
        $this->db->run(
            'INSERT INTO printdock_agents (agent_id, name, key_id, key_hash, revoked, poll_interval_s, hello_interval_s,'
            . ' lease_s, spool_timeout_s, printers_managed_by, printers, reported_printers, config_version, created_at)'
            . ' VALUES (:id, :name, :key_id, :key_hash, :revoked, :poll_interval_s, :hello_interval_s, :lease_s,'
            . ' :spool_timeout_s, :printers_managed_by, :printers, :reported_printers, :config_version, :created_at)',
            $admin + [
                'key_id' => $d['key_id'], 'key_hash' => $d['key_hash'],
                'reported_printers' => $this->encodePrinters($d['reported_printers']),
                'created_at' => PdoSupport::formatTime($d['created_at']),
            ],
        );
    }

    public function find(AgentId $id): ?Agent
    {
        $row = $this->db->fetchRow('SELECT * FROM printdock_agents WHERE agent_id = ?', [$id->value]);

        return null === $row ? null : $this->hydrate($row);
    }

    public function findByKeyId(string $keyId): ?Agent
    {
        $row = $this->db->fetchRow('SELECT * FROM printdock_agents WHERE key_id = ?', [$keyId]);

        return null === $row ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), $this->db->fetchRows('SELECT * FROM printdock_agents ORDER BY created_at, agent_id'));
    }

    public function delete(AgentId $id): void
    {
        $this->db->run('DELETE FROM printdock_agents WHERE agent_id = ?', [$id->value]);
    }

    public function recordHello(Agent $agent): void
    {
        $d = $agent->toData();
        $this->db->run(
            'UPDATE printdock_agents SET reported_printers = :reported, last_hello = :hello, last_hello_at = :at,'
            . ' last_seen_at = :seen, hello_count = hello_count + 1,'
            . ' empty_claim_at = NULL, empty_claim_printers = NULL WHERE agent_id = :id',
            [
                'reported' => $this->encodePrinters($d['reported_printers']),
                'hello' => null === $d['last_hello'] ? null : PdoSupport::encodeJson($d['last_hello']->toArray()),
                'at' => PdoSupport::formatTime($d['last_hello_at']),
                'seen' => PdoSupport::formatTime($d['last_seen_at']),
                'id' => $d['id'],
            ],
        );
    }

    public function recordActivity(AgentId $id, AgentActivity $activity, \DateTimeImmutable $now): void
    {
        $at = PdoSupport::formatTime($now);
        if (AgentActivity::Claim === $activity) {
            $this->db->run(
                'UPDATE printdock_agents SET last_seen_at = :seen, last_claim_at = :at, claim_count = claim_count + 1'
                . ' WHERE agent_id = :id',
                ['at' => $at, 'seen' => $at, 'id' => $id->value],
            );

            return;
        }
        $this->db->run('UPDATE printdock_agents SET last_seen_at = :at WHERE agent_id = :id', ['at' => $at, 'id' => $id->value]);
    }

    public function recordClaimResult(AgentId $id, ?\DateTimeImmutable $emptyAt, array $printerIds = []): void
    {
        $this->db->run(
            'UPDATE printdock_agents SET empty_claim_at = ?, empty_claim_printers = ? WHERE agent_id = ?',
            [PdoSupport::formatTime($emptyAt), null === $emptyAt ? null : PdoSupport::encodeJson($printerIds), $id->value],
        );
    }

    /**
     * @param list<Printer> $printers
     */
    private function encodePrinters(array $printers): string
    {
        return PdoSupport::encodeJson(array_map(static fn (Printer $p): array => $p->toArray(), $printers));
    }

    /**
     * @return list<Printer>
     */
    private function decodePrinters(mixed $json): array
    {
        $out = [];
        foreach (PdoSupport::decodeJson($json) as $entry) {
            if (is_array($entry)) {
                $out[] = Printer::fromArray($entry, false);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): Agent
    {
        $hello = null;
        if (null !== $r['last_hello']) {
            $hello = HelloReport::fromArray(PdoSupport::decodeJson($r['last_hello']));
        }

        return Agent::fromData([
            'id' => PdoSupport::string($r['agent_id']),
            'name' => PdoSupport::string($r['name']),
            'key_id' => PdoSupport::string($r['key_id']),
            'key_hash' => PdoSupport::string($r['key_hash']),
            'revoked' => 0 !== PdoSupport::int($r['revoked']),
            'settings' => new AgentSettings(
                PdoSupport::int($r['poll_interval_s']),
                PdoSupport::int($r['hello_interval_s']),
                PdoSupport::int($r['lease_s']),
                PdoSupport::int($r['spool_timeout_s']),
                ManagedBy::from(PdoSupport::string($r['printers_managed_by'])),
            ),
            'printers' => $this->decodePrinters($r['printers']),
            'reported_printers' => $this->decodePrinters($r['reported_printers']),
            'config_version' => PdoSupport::string($r['config_version']),
            'created_at' => PdoSupport::requiredTime($r['created_at']),
            'last_hello_at' => PdoSupport::parseTime($r['last_hello_at']),
            'last_claim_at' => PdoSupport::parseTime($r['last_claim_at']),
            'last_seen_at' => PdoSupport::parseTime($r['last_seen_at']),
            'hello_count' => PdoSupport::int($r['hello_count']),
            'claim_count' => PdoSupport::int($r['claim_count']),
            'last_hello' => $hello,
            'empty_claim_at' => PdoSupport::parseTime($r['empty_claim_at']),
            'empty_claim_printers' => null === $r['empty_claim_printers']
                ? [] : array_values(array_map(PdoSupport::string(...), PdoSupport::decodeJson($r['empty_claim_printers']))),
        ]);
    }
}
