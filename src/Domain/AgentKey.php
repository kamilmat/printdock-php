<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * An agent key `pa1_<8 lowercase alnum id>_<43 url-safe base64 chars>` (section 2).
 *
 * The server keeps only {@see hash()} (SHA-256 of the secret, hex) and finds the record by {@see keyId()}; the
 * secret is compared in constant time. The plaintext key exists once, at creation, and is shown to the operator.
 */
final class AgentKey
{
    private const PATTERN = '/^pa1_([a-z0-9]{8})_([A-Za-z0-9_-]{43})$/D';

    private function __construct(
        private readonly string $keyId,
        private readonly string $secret,
    ) {
    }

    public static function generate(): self
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $id = '';
        for ($i = 0; $i < 8; ++$i) {
            $id .= $alphabet[random_int(0, 35)];
        }
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new self($id, $secret);
    }

    /** Null when the string is not shaped like a PAP key at all. */
    public static function parse(string $key): ?self
    {
        if (1 !== preg_match(self::PATTERN, $key, $m)) {
            return null;
        }

        return new self($m[1], $m[2]);
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function hash(): string
    {
        return hash('sha256', $this->secret);
    }

    public function matches(string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash());
    }

    public function toString(): string
    {
        return sprintf('pa1_%s_%s', $this->keyId, $this->secret);
    }
}
