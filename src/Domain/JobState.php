<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Server-side job states (section 10). `expired` is deliberately absent: it is computed when reading, never stored.
 */
enum JobState: string
{
    case Queued = 'queued';
    case Leased = 'leased';
    case Spooled = 'spooled';
    case Printed = 'printed';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Printed, self::Failed, self::Uncertain, self::Cancelled => true,
            self::Queued, self::Leased, self::Spooled => false,
        };
    }
}
