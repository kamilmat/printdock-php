<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * The latest discovery result kept for an agent, with the request still open for it, if any (section 14.2:
 * the server keeps the latest result per agent, history is its own business).
 */
final class StoredDiscovery
{
    /**
     * @param array<string, mixed> $result the body the agent sent
     */
    public function __construct(
        public readonly \DateTimeImmutable $receivedAt,
        public readonly array $result,
    ) {
    }
}
