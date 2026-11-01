<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * What an agent reports in `ack` (section 6.2) and the job state each outcome leads to.
 */
enum Outcome: string
{
    case Printed = 'printed';
    case Spooled = 'spooled';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
    case Rejected = 'rejected';

    /** `rejected` is stored as `failed` with its reason; it never requeues. */
    public function resultingState(): JobState
    {
        return match ($this) {
            self::Printed => JobState::Printed,
            self::Spooled => JobState::Spooled,
            self::Failed, self::Rejected => JobState::Failed,
            self::Uncertain => JobState::Uncertain,
        };
    }
}
