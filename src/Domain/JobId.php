<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Opaque identifier of a print job; at most 128 characters (section 3).
 */
final class JobId
{
    public const MAX_LENGTH = 128;

    private function __construct(public readonly string $value)
    {
    }

    public static function generate(): self
    {
        return new self('pj_' . bin2hex(random_bytes(8)));
    }

    public static function fromString(string $value): self
    {
        if ('' === $value || strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('job_id must be 1 to 128 characters');
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
