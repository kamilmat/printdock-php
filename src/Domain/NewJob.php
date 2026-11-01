<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Everything a host decides when it creates a print job. The printer is concrete: a role is resolved to a
 * `printer_id` before the job exists (see PapAdmin), because a claim never resolves roles (section 4.3).
 *
 * `$redeliverAfterLeaseExpiry` false makes delivery "at most once": a lease that runs out closes the job as
 * `uncertain` instead of returning it to the queue, because its bytes may already have reached the printer (a
 * second courier label is worse than a missing one, which an operator reprints deliberately).
 */
final class NewJob
{
    /**
     * @param array<string, mixed>|null $options   PDF options of section 9.2; ignored for other formats
     * @param array<string, mixed>|null $reference opaque object echoed back to the host in the claim response
     */
    public function __construct(
        public readonly AgentId $agentId,
        public readonly string $printerId,
        public readonly ?string $printerRole,
        public readonly JobFormat $format,
        public readonly string $content,
        public readonly ContentDelivery $delivery = ContentDelivery::Inline,
        public readonly ?array $options = null,
        public readonly ?string $title = null,
        public readonly bool $titleSensitive = false,
        public readonly ?array $reference = null,
        public readonly float $notBeforeInSeconds = 0.0,
        public readonly float $expiresInSeconds = 900.0,
        public readonly bool $redeliverAfterLeaseExpiry = true,
    ) {
    }
}
