<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Request;

use PrintDock\Server\Domain\Input;
use PrintDock\Server\Domain\LeaseToken;
use PrintDock\Server\Domain\Outcome;
use PrintDock\Server\Domain\ReasonCode;

/**
 * `POST /jobs/{job_id}/ack` (section 6.1). `rejected` must carry a reason; any well-formed code is accepted
 * because agents may add codes (6.5).
 */
final class AckRequest
{
    public function __construct(
        public readonly LeaseToken $leaseToken,
        public readonly Outcome $outcome,
        public readonly ?ReasonCode $reason,
        public readonly ?string $detail,
        public readonly ?string $spoolJobId,
    ) {
    }

    /**
     * @param array<mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $outcome = Outcome::tryFrom(Input::requiredString($body, 'outcome', 32))
            ?? throw new \InvalidArgumentException('outcome must be printed, spooled, failed, uncertain or rejected');
        $rawReason = Input::optionalString($body, 'reason', 64);
        $reason = null === $rawReason ? null : ReasonCode::fromString($rawReason);
        if (Outcome::Rejected === $outcome && null === $reason) {
            throw new \InvalidArgumentException('rejected needs a reason');
        }

        return new self(
            LeaseToken::fromString(Input::requiredString($body, 'lease_token', LeaseToken::MAX_LENGTH)),
            $outcome,
            $reason,
            Input::optionalString($body, 'detail', 1000),
            Input::optionalString($body, 'spool_job_id', 128),
        );
    }
}
