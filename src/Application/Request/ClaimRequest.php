<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Request;

use PrintDock\Server\Domain\Input;

/**
 * `POST /jobs/claim` (section 5.1). `max` has no effect: PAP/1 hands out one job per call. `wait_s` is honoured
 * only up to `PapServerConfig::$longPollMaxS` (0 = never wait); a negative value counts as 0.
 */
final class ClaimRequest
{
    /**
     * @param list<string> $printerIds
     */
    public function __construct(
        public readonly array $printerIds,
        public readonly float $waitS = 0.0,
    ) {
    }

    /**
     * @param array<mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $ids = Input::stringList($body, 'printer_ids');
        if ([] === $ids) {
            throw new \InvalidArgumentException('printer_ids must be a non-empty list');
        }

        return new self($ids, max(0.0, Input::optionalFloat($body, 'wait_s', 0.0)));
    }
}
