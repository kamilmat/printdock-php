<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Response;

use PrintDock\Server\Domain\JobState;

/**
 * `ack` 200 body (section 6.3): the server-side status after the ack and whether it was late.
 */
final class AckResponse
{
    public function __construct(
        public readonly JobState $status,
        public readonly bool $late,
        public readonly string $serverTime,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'late' => $this->late, 'server_time' => $this->serverTime];
    }
}
