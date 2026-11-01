<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Response;

/**
 * `claim` answer: a job (200) or nothing (204 with `Retry-After`, section 5.3). `discover` rides on both when a
 * discovery request is open (section 14.1).
 */
final class ClaimResponse
{
    /**
     * @param array<string, mixed>|null $job the job body of section 5.2, without `discover`
     */
    public function __construct(
        public readonly ?array $job,
        public readonly int $retryAfterSeconds,
        public readonly ?string $discover,
    ) {
    }

    /**
     * @return array<string, mixed>|null null for the 204 case
     */
    public function toArray(): ?array
    {
        if (null === $this->job) {
            return null;
        }

        return null === $this->discover ? $this->job : $this->job + ['discover' => $this->discover];
    }
}
