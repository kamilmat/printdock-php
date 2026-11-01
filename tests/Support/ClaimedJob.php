<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Support;

/**
 * Typed view of a decoded claim response, so tests do not cast `mixed` everywhere.
 */
final class ClaimedJob
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(public readonly array $body)
    {
    }

    public function id(): string
    {
        return $this->string('job_id');
    }

    public function token(): string
    {
        return $this->string('lease_token');
    }

    public function string(string $key): string
    {
        $value = $this->body[$key] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException($key . ' is not a string');
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->body[$key] ?? null;
        if (!is_int($value)) {
            throw new \RuntimeException($key . ' is not an int');
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->body[$key] ?? null;
        if (!is_bool($value)) {
            throw new \RuntimeException($key . ' is not a bool');
        }

        return $value;
    }
}
