<?php

declare(strict_types=1);

namespace PrintDock\Server\Examples;

use PrintDock\Server\Application\PapAdmin;
use PrintDock\Server\Domain\AdminException;
use PrintDock\Server\Domain\Agent;
use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\AgentSettings;
use PrintDock\Server\Domain\ContentDelivery;
use PrintDock\Server\Domain\HistoryQuery;
use PrintDock\Server\Domain\Input;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobEvent;
use PrintDock\Server\Domain\JobFormat;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobSummary;
use PrintDock\Server\Domain\NewJob;
use PrintDock\Server\Domain\Printer;
use PrintDock\Server\Domain\Time;
use PrintDock\Server\Http\HttpResponse;
use Psr\Clock\ClockInterface;

/**
 * A tiny admin surface for the example: create agents, enqueue jobs, read states, ask for a printer scan.
 * Shaped like the test admin API the conformance suite drives with
 * `--admin-url`. A real host puts its own authorisation and UI here instead. Not for exposure: it needs an
 * admin token, and without one it answers 403 to everything unless started explicitly open (tests only).
 */
final class AdminApi
{
    public function __construct(
        private readonly PapAdmin $admin,
        private readonly ClockInterface $clock,
        private readonly ?string $token,
        private readonly string $papBaseUrl,
        private readonly bool $open = false,
    ) {
    }

    /**
     * @param array<string, string> $headers lower-cased names
     */
    public function handle(string $method, string $rel, array $headers, string $body): HttpResponse
    {
        if (null === $this->token || '' === $this->token) {
            if (!$this->open) {
                return $this->error(403, 'forbidden', 'set PRINTDOCK_ADMIN_TOKEN to use the admin API');
            }
        } elseif (!hash_equals($this->token, $headers['x-admin-token'] ?? '')) {
            return $this->error(401, 'unauthorized', 'X-Admin-Token required');
        }

        try {
            return $this->route($method, array_values(array_filter(explode('/', $rel))), $this->decode($body));
        } catch (AdminException $e) {
            $status = match ($e->kind) {
                AdminException::NOT_FOUND => 404,
                AdminException::CONFLICT, AdminException::NOT_SUPPORTED, AdminException::AGENT_NOT_REVOKED, AdminException::AGENT_HAS_OPEN_JOBS => 409,
                default => 400,
            };

            return $this->error($status, $e->kind, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error(400, 'bad_request', $e->getMessage());
        }
    }

    /**
     * @param list<string>         $parts
     * @param array<string, mixed> $body
     */
    private function route(string $method, array $parts, array $body): HttpResponse
    {
        $count = count($parts);
        if (['health'] === $parts) {
            return HttpResponse::json(200, ['ok' => true, 'server_time' => Time::iso($this->clock->now()), 'pap_base' => $this->papBaseUrl]);
        }
        if (['agents'] === $parts && 'POST' === $method) {
            return HttpResponse::json(201, $this->createAgent($body));
        }
        if (['agents'] === $parts && 'GET' === $method) {
            return HttpResponse::json(200, ['agents' => array_map($this->agentView(...), $this->admin->agents())]);
        }
        if (2 === $count && 'agents' === $parts[0]) {
            $id = AgentId::fromString($parts[1]);
            if ('PATCH' === $method) {
                return HttpResponse::json(200, $this->agentView($this->admin->updateAgent($id, fn (Agent $a) => $this->patchAgent($a, $body))));
            }
            $agent = $this->admin->findAgent($id) ?? throw new AdminException(AdminException::NOT_FOUND, 'unknown agent');

            return HttpResponse::json(200, $this->agentView($agent));
        }
        if (3 === $count && 'agents' === $parts[0] && 'discover' === $parts[2] && 'POST' === $method) {
            $requestId = $this->admin->requestDiscovery(AgentId::fromString($parts[1]));

            return HttpResponse::json(202, ['request_id' => $requestId, 'status' => 'requested']);
        }
        if (3 === $count && 'agents' === $parts[0] && 'discovered' === $parts[2] && 'GET' === $method) {
            $id = AgentId::fromString($parts[1]);
            $found = $this->admin->latestDiscovery($id);

            return HttpResponse::json(200, [
                'pending_request_id' => $this->admin->openDiscoveryRequest($id),
                'received_at' => Time::isoOrNull($found?->receivedAt),
                'result' => $found?->result,
            ]);
        }
        if (['jobs'] === $parts && 'POST' === $method) {
            return HttpResponse::json(201, $this->jobView($this->admin->enqueue($this->newJob($body))));
        }
        if (['jobs'] === $parts && 'GET' === $method) {
            $page = $this->admin->history(new HistoryQuery(limit: HistoryQuery::MAX_LIMIT));

            return HttpResponse::json(200, [
                'jobs' => array_map($this->summaryView(...), $page->jobs),
                'next_before' => $page->nextBefore,
            ]);
        }
        if (in_array($count, [2, 3], true) && 'jobs' === $parts[0]) {
            $id = JobId::fromString($parts[1]);
            if (3 === $count && 'cancel' === $parts[2] && 'POST' === $method) {
                return HttpResponse::json(200, $this->jobView($this->admin->cancel($id)));
            }
            if (3 === $count && 'events' === $parts[2] && 'GET' === $method) {
                return HttpResponse::json(200, ['events' => array_map($this->eventView(...), $this->admin->jobEvents($id))]);
            }
            $job = $this->admin->findJob($id) ?? throw new AdminException(AdminException::NOT_FOUND, 'unknown job');

            return HttpResponse::json(200, $this->jobView($job));
        }

        return $this->error(404, 'not_found', 'no such admin path');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function createAgent(array $body): array
    {
        $printers = array_map(
            static fn (mixed $p): Printer => Printer::fromArray(is_array($p) ? $p : throw new \InvalidArgumentException('printers[] entries must be objects'), true),
            Input::optionalList($body, 'printers'),
        );
        $registered = $this->admin->registerAgent(Input::optionalString($body, 'name') ?? 'agent', AgentSettings::fromArray($body), $printers);

        return $this->agentView($registered->agent) + ['key' => $registered->key];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patchAgent(Agent $agent, array $body): void
    {
        $agent->configure(AgentSettings::fromArray($body, $agent->settings()));
        $name = Input::optionalString($body, 'agent_name') ?? Input::optionalString($body, 'name');
        if (null !== $name) {
            $agent->rename($name);
        }
        if (array_key_exists('revoked', $body)) {
            Input::optionalBool($body, 'revoked', false) ? $agent->revoke() : $agent->restore();
        }
        if (array_key_exists('printers', $body)) {
            $agent->replacePrinters(array_map(
                static fn (mixed $p): Printer => Printer::fromArray(is_array($p) ? $p : throw new \InvalidArgumentException('printers[] entries must be objects'), true),
                Input::requiredList($body, 'printers'),
            ), Time::utc($this->clock->now()));
        }
        if (true === ($body['bump_config_version'] ?? false)) {
            $agent->bumpConfigVersion(Time::utc($this->clock->now()));
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function newJob(array $body): NewJob
    {
        $agentId = AgentId::fromString(Input::requiredString($body, 'agent_id', 128));
        $format = JobFormat::tryFrom(Input::optionalString($body, 'format') ?? 'zpl') ?? throw new \InvalidArgumentException('format must be zpl, epl, raw, pdf or png');
        if (is_string($body['content_base64'] ?? null)) {
            $content = base64_decode($body['content_base64'], true) ?: throw new \InvalidArgumentException('content_base64 is not valid base64');
        } elseif (is_string($body['content_text'] ?? null)) {
            $content = $body['content_text'];
        } else {
            throw new \InvalidArgumentException('content_base64 or content_text required');
        }
        $delivery = ContentDelivery::tryFrom(Input::optionalString($body, 'delivery') ?? 'inline') ?? throw new \InvalidArgumentException('delivery must be inline or url');
        $role = Input::optionalString($body, 'printer_role');

        return new NewJob(
            $agentId,
            $this->admin->resolvePrinterId($agentId, Input::optionalString($body, 'printer_id'), $role),
            $role,
            $format,
            $content,
            $delivery,
            Input::optionalMap($body, 'options'),
            Input::optionalString($body, 'title'),
            Input::optionalBool($body, 'title_sensitive', false),
            Input::optionalMap($body, 'reference'),
            Input::optionalFloat($body, 'not_before_s', 0.0),
            Input::optionalFloat($body, 'expires_in_s', 900.0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function agentView(Agent $agent): array
    {
        $s = $agent->settings();

        return [
            'agent_id' => $agent->id()->value,
            'agent_name' => $agent->name(),
            'key_id' => $agent->keyId(),
            'revoked' => $agent->isRevoked(),
            'poll_interval_s' => $s->pollIntervalS,
            'hello_interval_s' => $s->helloIntervalS,
            'lease_s' => $s->leaseS,
            'spool_timeout_s' => $s->spoolTimeoutS,
            'printers_managed_by' => $s->managedBy->value,
            'printers' => array_map(static fn (Printer $p): array => $p->toArray(), $agent->configuredPrinters()),
            'reported_printers' => array_map(static fn (Printer $p): array => $p->toArray(), $agent->reportedPrinters()),
            'config_version' => $agent->configVersion(),
            'created_at' => Time::iso($agent->createdAt()),
            'last_hello_at' => Time::isoOrNull($agent->lastHelloAt()),
            'last_claim_at' => Time::isoOrNull($agent->lastClaimAt()),
            'last_seen_at' => Time::isoOrNull($agent->lastSeenAt()),
            'hello_count' => $agent->helloCount(),
            'claim_count' => $agent->claimCount(),
            'last_hello' => $agent->lastHello()?->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobView(Job $job): array
    {
        $now = Time::utc($this->clock->now());

        return [
            'job_id' => $job->id()->value,
            'agent_id' => $job->agentId()->value,
            'printer_id' => $job->printerId(),
            'printer_role' => $job->printerRole(),
            'format' => $job->format()->value,
            'size' => $job->contentSize(),
            'status' => $job->reportedStatus($now),
            'stored_status' => $job->state()->value,
            'attempt' => $job->attempt(),
            'title' => $job->title(),
            'created_at' => Time::iso($job->createdAt()),
            'expires_at' => Time::iso($job->expiresAt()),
            'lease_until' => Time::isoOrNull($job->leaseUntil()),
            'finished_at' => Time::isoOrNull($job->finishedAt()),
            'outcome' => $job->outcome()?->value,
            'reason' => $job->reason()?->value,
            'detail' => $job->detail(),
            'spool_job_id' => $job->spoolJobId(),
            'late' => $job->wasLate(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryView(JobSummary $job): array
    {
        return [
            'job_id' => $job->id->value,
            'agent_id' => $job->agentId->value,
            'agent_name' => $job->agentName,
            'printer_id' => $job->printerId,
            'printer_name' => $job->printerName,
            'format' => $job->format->value,
            'size' => $job->contentSize,
            'content_purged' => $job->contentPurged,
            'status' => $job->reportedStatus(Time::utc($this->clock->now())),
            'attempt' => $job->attempt,
            'title' => $job->title,
            'created_at' => Time::iso($job->createdAt),
            'finished_at' => Time::isoOrNull($job->finishedAt),
            'outcome' => $job->outcome?->value,
            'reason' => $job->reason?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventView(JobEvent $event): array
    {
        return [
            'seq' => $event->seq,
            'at' => Time::iso($event->at),
            'event' => $event->type->value,
            'state' => $event->stateAfter->value,
            'attempt' => $event->attempt,
            'outcome' => $event->outcome?->value,
            'reason' => $event->reason?->value,
            'detail' => $event->detail,
            'late' => $event->late,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if ('' === $body) {
            return [];
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('body is not valid JSON');
        }

        return Input::object($data);
    }

    private function error(int $status, string $code, string $message): HttpResponse
    {
        return HttpResponse::json($status, ['error' => $code, 'message' => $message]);
    }
}
