<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Per-station timing and configuration mode, as sent in the `hello` response (section 4.2).
 *
 * Durations are whole seconds. `lease_s` also drives lease expiry, redelivery and the spooled-silence timeout
 * (`spool_timeout_s + lease_s`, section 10).
 */
final class AgentSettings
{
    public function __construct(
        public readonly int $pollIntervalS = 2,
        public readonly int $helloIntervalS = 60,
        public readonly int $leaseS = 60,
        public readonly int $spoolTimeoutS = 120,
        public readonly ManagedBy $managedBy = ManagedBy::Server,
    ) {
        foreach (['poll_interval_s' => $pollIntervalS, 'hello_interval_s' => $helloIntervalS,
            'lease_s' => $leaseS, 'spool_timeout_s' => $spoolTimeoutS] as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException(sprintf('%s must be at least 1', $name));
            }
        }
    }

    /**
     * @param array<mixed> $data absent keys keep the defaults of $base
     */
    public static function fromArray(array $data, ?self $base = null): self
    {
        $base ??= new self();
        $managed = $base->managedBy;
        if (null !== ($data['printers_managed_by'] ?? null)) {
            $managed = ManagedBy::tryFrom(Input::requiredString($data, 'printers_managed_by'))
                ?? throw new \InvalidArgumentException('printers_managed_by must be server or agent');
        }

        return new self(
            Input::optionalInt($data, 'poll_interval_s', $base->pollIntervalS),
            Input::optionalInt($data, 'hello_interval_s', $base->helloIntervalS),
            Input::optionalInt($data, 'lease_s', $base->leaseS),
            Input::optionalInt($data, 'spool_timeout_s', $base->spoolTimeoutS),
            $managed,
        );
    }

    /** How long a `spooled` job may stay silent before it becomes `uncertain`. */
    public function spoolSilenceS(): int
    {
        return $this->spoolTimeoutS + $this->leaseS;
    }
}
