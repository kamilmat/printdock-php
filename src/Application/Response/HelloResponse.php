<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Response;

use PrintDock\Server\Domain\Agent;
use PrintDock\Server\Domain\Printer;

/**
 * `hello` 200 body (section 4.2). `printers` is empty in `agent` mode: the agent ignores it there.
 */
final class HelloResponse
{
    /**
     * @param list<Printer> $printers
     */
    public function __construct(
        public readonly Agent $agent,
        public readonly array $printers,
        public readonly bool $contentUrl,
        public readonly ?string $discover,
        public readonly string $serverTime,
        public readonly int $longPollMaxS = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $settings = $this->agent->settings();
        $body = [
            'agent_id' => $this->agent->id()->value,
            'agent_name' => $this->agent->name(),
            'poll_interval_s' => $settings->pollIntervalS,
            'hello_interval_s' => $settings->helloIntervalS,
            'lease_s' => $settings->leaseS,
            'spool_timeout_s' => $settings->spoolTimeoutS,
            'printers_managed_by' => $settings->managedBy->value,
            'config_version' => $this->agent->configVersion(),
            'printers' => array_map(static fn (Printer $p): array => $p->toArray(), $this->printers),
            'server_capabilities' => [
                'long_poll_max_s' => $this->longPollMaxS,
                'content_url' => $this->contentUrl,
                'enroll' => false,
                'lease_extend' => true,
                'discovery' => true,
            ],
            'server_time' => $this->serverTime,
        ];
        if (null !== $this->discover) {
            $body['discover'] = $this->discover;
        }

        return $body;
    }
}
