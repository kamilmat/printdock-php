<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Response;

/**
 * `discovery` 200 body (section 14.2).
 */
final class DiscoveryResponse
{
    public function __construct(
        public readonly bool $matched,
        public readonly string $serverTime,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['status' => 'stored', 'matched' => $this->matched, 'server_time' => $this->serverTime];
    }
}
