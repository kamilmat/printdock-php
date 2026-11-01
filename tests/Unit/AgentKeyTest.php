<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrintDock\Server\Domain\AgentKey;

final class AgentKeyTest extends TestCase
{
    public function test_a_generated_key_has_the_pa1_shape_of_section_2(): void
    {
        $key = AgentKey::generate();

        self::assertMatchesRegularExpression('/^pa1_[a-z0-9]{8}_[A-Za-z0-9_-]{43}$/', $key->toString());
        self::assertSame(8, strlen($key->keyId()));
    }

    public function test_a_key_survives_a_parse_round_trip(): void
    {
        $key = AgentKey::generate();

        $parsed = AgentKey::parse($key->toString());

        self::assertNotNull($parsed);
        self::assertSame($key->toString(), $parsed->toString());
        self::assertSame($key->keyId(), $parsed->keyId());
    }

    public function test_only_a_sha256_hash_of_the_secret_is_kept_for_storage(): void
    {
        $key = AgentKey::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key->hash());
        self::assertStringNotContainsString(substr($key->toString(), 13), $key->hash());
        self::assertTrue($key->matches($key->hash()));
    }

    public function test_a_different_secret_does_not_match_the_stored_hash(): void
    {
        $stored = AgentKey::generate()->hash();

        self::assertFalse(AgentKey::generate()->matches($stored));
    }

    #[DataProvider('malformedKeys')]
    public function test_a_malformed_key_does_not_parse(string $malformed): void
    {
        self::assertNull(AgentKey::parse($malformed));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedKeys(): iterable
    {
        $secret = str_repeat('A', 43);
        yield 'wrong prefix' => ['pa2_abcd1234_' . $secret];
        yield 'upper-case id' => ['pa1_ABCD1234_' . $secret];
        yield 'short id' => ['pa1_abcd123_' . $secret];
        yield 'long id' => ['pa1_abcd12345_' . $secret];
        yield 'short secret' => ['pa1_abcd1234_' . str_repeat('A', 42)];
        yield 'padded secret' => ['pa1_abcd1234_' . str_repeat('A', 42) . '='];
        yield 'empty' => [''];
        yield 'trailing junk' => ['pa1_abcd1234_' . $secret . "\n"];
    }
}
