<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * The result of a successful `claimNext`: the leased job and whether it repeats a lease the agent already had.
 */
final class Claim
{
    public function __construct(
        public readonly Job $job,
        public readonly bool $redelivered,
    ) {
    }
}
