<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Request;

use PrintDock\Server\Domain\Input;
use PrintDock\Server\Domain\LeaseToken;

/**
 * `POST /jobs/{job_id}/extend` (section 5.6).
 */
final class ExtendRequest
{
    public function __construct(public readonly LeaseToken $leaseToken)
    {
    }

    /**
     * @param array<mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(LeaseToken::fromString(Input::requiredString($body, 'lease_token', LeaseToken::MAX_LENGTH)));
    }
}
