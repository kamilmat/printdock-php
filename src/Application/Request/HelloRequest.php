<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Request;

use PrintDock\Server\Domain\HelloReport;

/**
 * `POST /hello` (section 4.1).
 */
final class HelloRequest
{
    public function __construct(public readonly HelloReport $report)
    {
    }

    /**
     * @param array<mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(HelloReport::fromArray($body));
    }
}
