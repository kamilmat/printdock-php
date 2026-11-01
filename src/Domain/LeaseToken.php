<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Identifies one lease of a job. Equality is constant-time because the token is a bearer capability for `ack` and `extend`.
 */
final class LeaseToken
{
    public const MAX_LENGTH = 128;

    private function __construct(public readonly string $value)
    {
    }

    public static function generate(): self
    {
        return new self('lt_' . bin2hex(random_bytes(12)));
    }

    public static function fromString(string $value): self
    {
        if ('' === $value || strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('lease_token must be 1 to 128 characters');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
