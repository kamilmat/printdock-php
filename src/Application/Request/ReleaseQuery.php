<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Request;

/**
 * `GET /release?os=&arch=&current=` (section 8).
 */
final class ReleaseQuery
{
    public function __construct(
        public readonly string $os,
        public readonly string $arch,
        public readonly string $current,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromArray(array $query): self
    {
        $get = static fn (string $key): string => is_string($query[$key] ?? null) ? $query[$key] : '';

        return new self($get('os'), $get('arch'), $get('current'));
    }
}
