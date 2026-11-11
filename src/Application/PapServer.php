<?php

declare(strict_types=1);

namespace PrintDock\Server\Application;

use PrintDock\Server\Application\Request\AckRequest;
use PrintDock\Server\Application\Request\ClaimRequest;
use PrintDock\Server\Application\Request\ExtendRequest;
use PrintDock\Server\Application\Request\HelloRequest;
use PrintDock\Server\Application\Request\ReleaseQuery;
use PrintDock\Server\Application\Response\AckResponse;
use PrintDock\Server\Application\Response\ClaimResponse;
use PrintDock\Server\Application\Response\ContentResponse;
use PrintDock\Server\Application\Response\DiscoveryResponse;
use PrintDock\Server\Application\Response\ExtendResponse;
use PrintDock\Server\Application\Response\HelloResponse;
use PrintDock\Server\Domain\Agent;
use PrintDock\Server\Domain\AgentActivity;
use PrintDock\Server\Domain\AgentKey;
use PrintDock\Server\Domain\Claim;
use PrintDock\Server\Domain\ContentDelivery;
use PrintDock\Server\Domain\DiscoveryReport;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobState;
use PrintDock\Server\Domain\ManagedBy;
use PrintDock\Server\Domain\PdfOptions;
use PrintDock\Server\Domain\Printer;
use PrintDock\Server\Domain\ProtocolError;
use PrintDock\Server\Domain\Time;
use PrintDock\Server\Port\AgentRepository;
use PrintDock\Server\Port\DiscoveryStore;
use PrintDock\Server\Port\JobRepository;
use PrintDock\Server\Port\ReleaseProvider;
use Psr\Clock\ClockInterface;

/**
 * The server side of PAP/1: one method per endpoint, plain objects in and out, no HTTP types.
 *
 * Call order for a request is {@see checkVersion()}, {@see authenticate()}, then the endpoint method with the
 * authenticated agent; the HTTP adapter does exactly that. Every rule of sections 4 to 8, 10 and 14 that
 * concerns a single job lives in {@see Job}; this class orchestrates ports and builds responses.
 *
 * `$sleep` (microseconds) is how a long-polling claim waits between attempts; tests pass one that advances
 * their clock instead of sleeping.
 */
final class PapServer
{
    /** How often a long-polling claim looks again. */
    public const LONG_POLL_STEP_US = 250_000;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep
     */
    public function __construct(
        private readonly AgentRepository $agents,
        private readonly JobRepository $jobs,
        private readonly DiscoveryStore $discovery,
        private readonly ClockInterface $clock,
        private readonly PapServerConfig $config = new PapServerConfig(),
        private readonly ?ReleaseProvider $releases = null,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function serverTime(): string
    {
        return Time::iso($this->clock->now());
    }

    /**
     * @throws ProtocolError 400 `unsupported_version` unless the header is present with major version 1
     */
    public function checkVersion(?string $header): void
    {
        if (null === $header || '1' !== trim(explode('.', $header)[0])) {
            throw ProtocolError::unsupportedVersion();
        }
    }

    /**
     * A key matching the station's pending key ({@see PapAdmin::issueKey()}) is promoted to the current key on
     * its first use; from then on the old key is unauthorized. A revoked station's pending key is not promoted.
     *
     * @param string|null $authorization the raw `Authorization` header value
     *
     * @throws ProtocolError 401 `unauthorized` or `revoked`
     */
    public function authenticate(?string $authorization): Agent
    {
        if (null === $authorization || 1 !== preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $m)) {
            throw ProtocolError::unauthorized('missing bearer key');
        }
        $key = AgentKey::parse($m[1]);
        $agent = null === $key ? null : $this->agents->findByKeyId($key->keyId());
        if (null === $key || null === $agent) {
            throw ProtocolError::unauthorized();
        }
        $pending = !$agent->authenticatesWith($key);
        if ($pending && !$agent->authenticatesWithPendingKey($key)) {
            throw ProtocolError::unauthorized();
        }
        if ($agent->isRevoked()) {
            throw ProtocolError::revoked();
        }
        if ($pending) {
            // First use of a newly issued key: it becomes the current one. Read again to see who won a race.
            $this->agents->promotePendingKey($agent->id(), $key->keyId());
            $agent = $this->agents->find($agent->id());
            if (null === $agent || !$agent->authenticatesWith($key)) {
                throw ProtocolError::unauthorized();
            }
            if ($agent->isRevoked()) {
                throw ProtocolError::revoked();
            }
        }

        return $agent;
    }

    public function hello(Agent $agent, HelloRequest $request): HelloResponse
    {
        $now = $this->now();
        $report = $request->report;
        $reported = [];
        if (ManagedBy::Agent === $agent->settings()->managedBy) {
            try {
                foreach ($report->printers as $entry) {
                    if (!is_array($entry)) {
                        throw new \InvalidArgumentException('printers[] entries must be objects');
                    }
                    $reported[] = Printer::fromArray($entry, false);
                }
            } catch (\InvalidArgumentException $e) {
                throw ProtocolError::badRequest($e->getMessage());
            }
        }
        $agent->recordHello($report, $reported, $now);
        $this->agents->recordHello($agent);

        return new HelloResponse(
            $agent,
            ManagedBy::Server === $agent->settings()->managedBy ? $agent->configuredPrinters() : [],
            null !== $this->config->contentBaseUrl,
            $report->discovery ? $this->discovery->openRequestId($agent->id()) : null,
            Time::iso($now),
            $this->config->longPollMaxS,
        );
    }

    /**
     * Hands out the next job (5.4). With `wait_s` and long polling enabled the request is held until a job or a
     * scan request appears, or the wait ends; each attempt is its own short transaction, nothing is locked while
     * sleeping.
     *
     * A station that comes back sooner than {@see Agent::claimCooldownS()} after a claim that was empty and did
     * not wait gets 429 with `Retry-After`: it ignores the interval it was told and would load the host. A claim
     * right after a delivered job, after a long poll that waited, or offering a printer the empty claim did not
     * (it has just become free) is never limited.
     */
    public function claim(Agent $agent, ClaimRequest $request): ClaimResponse
    {
        $now = $this->now();
        $settings = $agent->settings();
        $printerIds = $agent->knownPrinterIds($request->printerIds);
        if ([] === $printerIds) {
            throw ProtocolError::badRequest('none of printer_ids is known for this agent');
        }
        $tooSoon = $this->config->claimCooldown ? $agent->claimRateLimitLeft($now, $printerIds) : 0.0;
        if ($tooSoon > 0) {
            // the station is alive even though its claim is refused; the claim itself is not counted
            $this->agents->recordActivity($agent->id(), AgentActivity::Other, $now);

            throw ProtocolError::rateLimited((int) ceil($tooSoon));
        }
        $this->agents->recordActivity($agent->id(), AgentActivity::Claim, $now);
        $deadline = Time::add($now, min($request->waitS, (float) $this->config->longPollMaxS));
        $waited = false;
        // a long poll sweeps on its first attempt and then every half lease, not every step: on SQLite each sweep
        // takes the database write lock
        $sweepEvery = $settings->leaseS / 2;
        $sweptAt = null;
        while (true) {
            if (null === $sweptAt || Time::secondsBetween($sweptAt, $now) >= $sweepEvery) {
                $this->jobs->sweep($agent->id(), $now, $settings->spoolSilenceS());
                $sweptAt = $now;
            }
            $claim = $this->jobs->claimNext($agent->id(), $printerIds, $now, $settings->leaseS);
            $discover = true === $agent->announcesDiscovery() ? $this->discovery->openRequestId($agent->id()) : null;
            $left = Time::secondsBetween($now, $deadline);
            if (null !== $claim || null !== $discover || $left <= 0) {
                break;
            }
            ($this->sleep)((int) min(self::LONG_POLL_STEP_US, ceil($left * 1_000_000)));
            $waited = true;
            $now = $this->now();
        }
        $emptyAt = null === $claim && null === $discover && !$waited ? $now : null;
        if ($this->config->claimCooldown && (null !== $emptyAt || null !== $agent->emptyClaimAt())) {
            $this->agents->recordClaimResult($agent->id(), $emptyAt, null === $emptyAt ? [] : $printerIds);
        }

        if (null !== $claim) {
            return new ClaimResponse($this->claimBody($claim, $agent, $now), $settings->pollIntervalS, $discover);
        }
        $retry = $settings->pollIntervalS;
        $readyAt = $this->jobs->earliestNotBefore($agent->id(), $now);
        if (null !== $readyAt) {
            $retry = max($retry, (int) ceil(Time::secondsBetween($now, $readyAt)));
        }

        return new ClaimResponse(null, $retry, $discover);
    }

    public function ack(Agent $agent, string $jobId, AckRequest $request): AckResponse
    {
        $now = $this->now();
        $silence = $agent->settings()->spoolSilenceS();
        $result = $this->jobs->modify(
            $this->jobIdOrNotFound($jobId),
            function (Job $job) use ($agent, $request, $now, $silence) {
                $this->assertOwnedBy($job, $agent);
                $job->applyTimeouts($now, $silence);

                return $job->acknowledge(
                    $request->leaseToken,
                    $request->outcome,
                    $request->reason,
                    $request->detail,
                    $request->spoolJobId,
                    $now,
                );
            },
        ) ?? throw ProtocolError::notFound('unknown job');
        $this->agents->recordActivity($agent->id(), AgentActivity::Other, $now);

        return new AckResponse($result->state, $result->late, Time::iso($now));
    }

    public function extend(Agent $agent, string $jobId, ExtendRequest $request): ExtendResponse
    {
        $now = $this->now();
        $settings = $agent->settings();
        $until = $this->jobs->modify(
            $this->jobIdOrNotFound($jobId),
            function (Job $job) use ($agent, $request, $now, $settings): \DateTimeImmutable {
                $this->assertOwnedBy($job, $agent);
                $job->applyTimeouts($now, $settings->spoolSilenceS());

                return $job->extend($request->leaseToken, $now, $settings->leaseS);
            },
        ) ?? throw ProtocolError::notFound('unknown job');
        $this->agents->recordActivity($agent->id(), AgentActivity::Other, $now);

        return new ExtendResponse(Time::iso($until), Time::iso($now));
    }

    public function content(Agent $agent, string $jobId): ContentResponse
    {
        $now = $this->now();
        $job = $this->jobs->find($this->jobIdOrNotFound($jobId));
        if (null === $job || !$job->agentId()->equals($agent->id())) {
            throw ProtocolError::notFound('content not available');
        }
        $job->applyTimeouts($now, $agent->settings()->spoolSilenceS());
        if (!in_array($job->state(), [JobState::Leased, JobState::Spooled], true)) {
            throw ProtocolError::notFound('content not available');
        }
        $this->agents->recordActivity($agent->id(), AgentActivity::Other, $now);

        return new ContentResponse($job->content(), $job->format()->contentType());
    }

    /**
     * @return array<string, mixed>|null the release body, or null for 204
     */
    public function release(Agent $agent, ReleaseQuery $query): ?array
    {
        $now = $this->now();
        $this->agents->recordActivity($agent->id(), AgentActivity::Other, $now);
        $release = $this->releases?->releaseFor($query->os, $query->arch, $query->current);

        return null === $release ? null : $release->toArray() + ['server_time' => Time::iso($now)];
    }

    public function discovery(Agent $agent, DiscoveryReport $report): DiscoveryResponse
    {
        $now = $this->now();
        $matched = $this->discovery->storeResult($agent->id(), $report, $now);
        $this->agents->recordActivity($agent->id(), AgentActivity::Other, $now);

        return new DiscoveryResponse($matched, Time::iso($now));
    }

    private function now(): \DateTimeImmutable
    {
        return Time::utc($this->clock->now());
    }

    private function jobIdOrNotFound(string $jobId): JobId
    {
        try {
            return JobId::fromString($jobId);
        } catch (\InvalidArgumentException) {
            throw ProtocolError::notFound('unknown job');
        }
    }

    /** A job of another agent is indistinguishable from an unknown one (6.4.1). */
    private function assertOwnedBy(Job $job, Agent $agent): void
    {
        if (!$job->agentId()->equals($agent->id())) {
            throw ProtocolError::notFound('unknown job');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function claimBody(Claim $claim, Agent $agent, \DateTimeImmutable $now): array
    {
        $job = $claim->job;
        $leaseToken = $job->leaseToken();
        $leaseUntil = $job->leaseUntil();
        if (null === $leaseToken || null === $leaseUntil) {
            throw new \LogicException('a claimed job must carry a lease');
        }
        $options = null === $job->options() ? null : PdfOptions::forAgent($job->options(), $agent->layoutCapabilities());
        $body = [
            'job_id' => $job->id()->value,
            'printer_id' => $job->printerId(),
            'printer_role' => $job->printerRole(),
            'format' => $job->format()->value,
            'content_sha256' => $job->contentSha256(),
            'options' => null === $options ? null : ([] === $options ? new \stdClass() : $options),
            'lease_token' => $leaseToken->value,
            'lease_until' => Time::iso($leaseUntil),
            'expires_at' => Time::iso($job->expiresAt()),
            'expires_in_s' => round(Time::secondsBetween($now, $job->expiresAt()), 3),
            'attempt' => $job->attempt(),
            'redelivered' => $claim->redelivered,
            'title' => $job->title(),
            'title_sensitive' => $job->titleSensitive(),
            'reference' => null === $job->reference() ? null : ([] === $job->reference() ? new \stdClass() : $job->reference()),
            'server_time' => Time::iso($now),
        ];
        $body = array_filter($body, static fn (mixed $v): bool => null !== $v);

        $url = ContentDelivery::Url === $job->delivery() ? $this->config->contentUrlFor($job->id()->value) : null;
        if (null !== $url) {
            $body['content_url'] = $url;
        } else {
            $body['content_base64'] = base64_encode($job->content());
        }

        return $body;
    }
}
