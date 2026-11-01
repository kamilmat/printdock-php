<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * A machine reason code from an ack (section 6.1, 6.5).
 *
 * Any code matching the pattern is accepted and stored, because agents may add codes; {@see KNOWN} lists the
 * ones the PrintDock agent sends so a host can pick messages for them.
 */
final class ReasonCode
{
    public const PATTERN = '/^[a-z][a-z0-9_]{0,39}$/D';

    public const KNOWN = [
        'expired', 'unsupported_route', 'unknown_printer', 'bad_content', 'bad_options',
        'unsupported_queue', 'print_timeout', 'spool_timeout', 'printer_unreachable', 'printer_io_error',
        'bad_printer_address', 'spooler_error', 'driver_error', 'cups_error', 'render_error',
        'print_process_failed', 'content_unavailable', 'agent_stopped', 'internal_error', 'printer_error',
    ];

    /**
     * Set by the server, not an agent: a job enqueued without redelivery after lease expiry whose lease ran out
     * without an ack, closed as `uncertain` ({@see Job::applyTimeouts()}).
     */
    public const LEASE_EXPIRED = 'lease_expired';

    private function __construct(public readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('reason must be a lower-case code like unsupported_queue');
        }

        return new self($value);
    }

    public function isKnown(): bool
    {
        return in_array($this->value, self::KNOWN, true);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
