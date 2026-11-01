<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Response;

/**
 * `extend` 200 body (section 5.6).
 */
final class ExtendResponse
{
    public function __construct(
        public readonly string $leaseUntil,
        public readonly string $serverTime,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['lease_until' => $this->leaseUntil, 'server_time' => $this->serverTime];
    }
}
