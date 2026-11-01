<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * The body of `POST /discovery` (section 14.2), validated: at most 256 printers, each with an address, ports
 * and `already_configured`. The rest of the body is kept verbatim for the host's UI.
 */
final class DiscoveryReport
{
    public const MAX_PRINTERS = 256;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public readonly ?string $requestId,
        public readonly array $payload,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $printers = Input::requiredList($data, 'discovered_printers');
        if (count($printers) > self::MAX_PRINTERS) {
            throw new \InvalidArgumentException('discovered_printers may hold at most 256 entries');
        }
        foreach ($printers as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('each discovered printer must be an object');
            }
            Input::requiredString($entry, 'address', 255);
            Input::requiredList($entry, 'ports');
            if (!is_bool($entry['already_configured'] ?? null)) {
                throw new \InvalidArgumentException('already_configured is required and must be a boolean');
            }
        }
        $requestId = Input::optionalString($data, 'request_id', AgentId::MAX_LENGTH);

        /** @var array<string, mixed> $payload */
        $payload = $data;

        return new self($requestId, $payload);
    }
}
