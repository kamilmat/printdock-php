<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrintDock\Server\Domain\Agent;
use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\AgentKey;
use PrintDock\Server\Domain\AgentSettings;

final class AgentPendingKeyTest extends TestCase
{
    public function test_data_without_pending_keys_loads_as_an_agent_without_one(): void
    {
        $key = AgentKey::generate();
        $data = Agent::register(AgentId::generate(), 'Packing 1', $key, new AgentSettings(), [], new \DateTimeImmutable())->toData();
        unset($data['pending_key_id'], $data['pending_key_hash']);

        $agent = Agent::fromData($data);

        self::assertFalse($agent->hasPendingKey());
        self::assertTrue($agent->authenticatesWith($key));
        self::assertNull($agent->toData()['pending_key_id'] ?? null);
    }

    public function test_the_pending_key_authenticates_only_as_pending_and_only_with_its_own_id_and_secret(): void
    {
        $current = AgentKey::generate();
        $pending = AgentKey::generate();
        $data = Agent::register(AgentId::generate(), 'Packing 1', $current, new AgentSettings(), [], new \DateTimeImmutable())->toData();
        $agent = Agent::fromData(['pending_key_id' => $pending->keyId(), 'pending_key_hash' => $pending->hash()] + $data);
        $forged = AgentKey::parse('pa1_' . $pending->keyId() . '_' . str_repeat('A', 43));
        self::assertNotNull($forged);

        self::assertTrue($agent->hasPendingKey());
        self::assertTrue($agent->authenticatesWithPendingKey($pending));
        self::assertFalse($agent->authenticatesWith($pending));
        self::assertFalse($agent->authenticatesWithPendingKey($current));
        self::assertFalse($agent->authenticatesWithPendingKey($forged));
        self::assertTrue($agent->authenticatesWith($current));
    }
}
