<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Opaque identifier of an agent (a station); at most 128 characters, never parsed by the other side (section 3).
 */
final class AgentId
{
    public const MAX_LENGTH = 128;

    private function __construct(public readonly string $value)
    {
    }

    public static function generate(): self
    {
        return new self('ag_' . bin2hex(random_bytes(6)));
    }

    public static function fromString(string $value): self
    {
        if ('' === $value || strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('agent_id must be 1 to 128 characters');
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
