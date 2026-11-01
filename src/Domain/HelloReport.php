<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * What an agent reports in `hello` (section 4.1), validated. Kept on the agent record so a host UI can show
 * version, state, errors and the system printers the operator may choose from.
 */
final class HelloReport
{
    /**
     * @param list<string>                $formats
     * @param list<array<string, mixed>>  $seenPrinters
     * @param list<mixed>                 $printers      raw `printers[]`, only meaningful in `agent` mode
     * @param array<string, mixed>        $capabilities
     */
    public function __construct(
        public readonly string $version,
        public readonly string $os,
        public readonly string $arch,
        public readonly string $hostname,
        public readonly string $state,
        public readonly array $capabilities,
        public readonly array $formats,
        public readonly bool $discovery,
        public readonly array $seenPrinters,
        public readonly array $printers,
        public readonly ?string $configVersion,
        public readonly ?string $lastError,
        public readonly int $spooledJobs,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $agent = Input::requiredMap($data, 'agent');
        $caps = Input::requiredMap($data, 'capabilities');
        $state = Input::optionalString($agent, 'state', 16) ?? 'active';
        if (!in_array($state, ['active', 'paused'], true)) {
            throw new \InvalidArgumentException('agent.state must be active or paused');
        }
        Input::stringList($caps, 'transports');
        $formats = Input::stringList($caps, 'formats');

        $seen = [];
        foreach (Input::optionalList($data, 'seen_printers') as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $seen[] = $entry;
            }
        }

        return new self(
            Input::requiredString($agent, 'version', 64),
            Input::requiredString($agent, 'os', 32),
            Input::requiredString($agent, 'arch', 32),
            Input::requiredString($agent, 'hostname', 255),
            $state,
            $caps,
            $formats,
            true === ($caps['discovery'] ?? false),
            $seen,
            Input::optionalList($data, 'printers'),
            Input::optionalString($data, 'config_version', 64),
            Input::optionalString($data, 'last_error', 500),
            Input::optionalInt($data, 'spooled_jobs', 0),
        );
    }

    /**
     * `capabilities.layout` (section 4.1): the layout features beyond PAP/1's first options the agent understands.
     * Anything malformed counts as nothing announced.
     *
     * @return list<string>
     */
    public function layout(): array
    {
        $layout = $this->capabilities['layout'] ?? null;

        return is_array($layout) ? array_values(array_filter($layout, is_string(...))) : [];
    }

    /**
     * Round-trips through {@see fromArray()}.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agent' => ['version' => $this->version, 'os' => $this->os, 'arch' => $this->arch,
                'hostname' => $this->hostname, 'state' => $this->state],
            'capabilities' => $this->capabilities,
            'seen_printers' => $this->seenPrinters,
            'printers' => $this->printers,
            'config_version' => $this->configVersion,
            'last_error' => $this->lastError,
            'spooled_jobs' => $this->spooledJobs,
        ];
    }
}
