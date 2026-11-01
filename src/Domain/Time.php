<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Time helpers for the wire format of section 3: UTC, ISO 8601 with a `Z` suffix, millisecond precision.
 */
final class Time
{
    public static function utc(\DateTimeInterface $time): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function add(\DateTimeImmutable $time, float|int $seconds): \DateTimeImmutable
    {
        $micro = (int) round($seconds * 1_000_000);

        return $time->modify(sprintf('%s%d microseconds', $micro < 0 ? '-' : '+', abs($micro)));
    }

    public static function iso(\DateTimeInterface $time): string
    {
        return self::utc($time)->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function isoOrNull(?\DateTimeInterface $time): ?string
    {
        return null === $time ? null : self::iso($time);
    }

    /** Seconds from `$from` to `$to`; negative when `$to` is earlier. */
    public static function secondsBetween(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        return (float) $to->format('U.u') - (float) $from->format('U.u');
    }
}
