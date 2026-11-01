<?php

declare(strict_types=1);

namespace PrintDock\Server\Application;

use PrintDock\Server\Domain\Agent;

/**
 * A freshly registered agent together with its plaintext key. The key is not recoverable afterwards (only its
 * hash is stored), so the host must show it to the operator now.
 */
final class RegisteredAgent
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $key,
    ) {
    }
}
