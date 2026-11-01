<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * The server-side state after an ack and whether the ack was late (section 6.3).
 */
final class AckResult
{
    public function __construct(
        public readonly JobState $state,
        public readonly bool $late,
    ) {
    }
}
