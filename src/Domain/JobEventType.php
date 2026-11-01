<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * What happened to a job in one recorded step of its history. `expired` is absent for the same reason it is
 * absent from {@see JobState}: it is computed when reading, nothing changes in storage when a deadline passes.
 */
enum JobEventType: string
{
    case Enqueued = 'enqueued';
    case Leased = 'leased';
    case Redelivered = 'redelivered';
    /**
     * The sweep returned a job whose lease ran out to `queued` (5.4.1), or closed it as `uncertain` when it was
     * enqueued without redelivery after lease expiry (`state_after` tells which).
     */
    case LeaseExpired = 'lease_expired';
    case Acked = 'acked';
    /** The sweep closed a `spooled` job silent for too long as `uncertain` (section 10). */
    case SpoolSilence = 'spool_silence';
    case Cancelled = 'cancelled';
}
