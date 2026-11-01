<?php

declare(strict_types=1);

namespace PrintDock\Server\Adapter\InMemory;

use Psr\Clock\ClockInterface;

/**
 * A PSR-20 clock that only moves when told to. For tests of lease expiry and the like.
 */
final class MutableClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new \DateTimeImmutable('2026-10-30T13:41:30Z');
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(float|int $seconds): void
    {
        $micro = (int) round($seconds * 1_000_000);
        $this->now = $this->now->modify(sprintf('%s%d microseconds', $micro < 0 ? '-' : '+', abs($micro)));
    }
}
