<?php

declare(strict_types=1);

namespace PrintDock\Server\Application;

use PrintDock\Server\Domain\AdminException;
use PrintDock\Server\Domain\Agent;
use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\AgentKey;
use PrintDock\Server\Domain\AgentSettings;
use PrintDock\Server\Domain\ContentDelivery;
use PrintDock\Server\Domain\HistoryPage;
use PrintDock\Server\Domain\HistoryQuery;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobEvent;
use PrintDock\Server\Domain\JobFormat;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobState;
use PrintDock\Server\Domain\JobSummary;
use PrintDock\Server\Domain\NewJob;
use PrintDock\Server\Domain\Printer;
use PrintDock\Server\Domain\ProtocolError;
use PrintDock\Server\Domain\StoredDiscovery;
use PrintDock\Server\Domain\Time;
use PrintDock\Server\Port\AgentRepository;
use PrintDock\Server\Port\DiscoveryStore;
use PrintDock\Server\Port\JobRepository;
use Psr\Clock\ClockInterface;

/**
 * The operator side: register agents, change their configuration, enqueue and cancel jobs, read the job history,
 * apply retention, ask for a printer scan. Not part of PAP; a host puts its own authorisation and UI in front of
 * it. Retention is the host's call: run {@see purgeContent()} and {@see purgeHistory()} from its scheduler
 * (suggested: content after 7 days, history after 365). Both first apply the lease and spool timeouts of every
 * station ({@see sweepAll()}): a station that stopped claiming never sweeps its own jobs, and a `leased` or
 * `spooled` job would otherwise never become closed and never be purged.
 */
final class PapAdmin
{
    public function __construct(
        private readonly AgentRepository $agents,
        private readonly JobRepository $jobs,
        private readonly DiscoveryStore $discovery,
        private readonly ClockInterface $clock,
        private readonly PapServerConfig $config = new PapServerConfig(),
    ) {
    }

    /**
     * @param list<Printer> $printers
     */
    public function registerAgent(string $name, AgentSettings $settings, array $printers = []): RegisteredAgent
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $key = AgentKey::generate();
            if (null === $this->agents->findByKeyId($key->keyId())) {
                $agent = Agent::register(AgentId::generate(), $name, $key, $settings, $printers, $this->now());
                $this->agents->save($agent);

                return new RegisteredAgent($agent, $key->toString());
            }
        }

        throw new \RuntimeException('could not find an unused key id');
    }

    /**
     * Applies `$change` to the agent and saves it. Revoking, renaming, reconfiguring and replacing printers all
     * go through here; only administrative fields are written, so a concurrent hello is not lost.
     *
     * @param callable(Agent): void $change
     */
    public function updateAgent(AgentId $id, callable $change): Agent
    {
        $agent = $this->agents->find($id) ?? throw new AdminException(AdminException::NOT_FOUND, 'unknown agent');
        $change($agent);
        $this->agents->save($agent);

        return $agent;
    }

    /**
     * Permanently removes a revoked agent and its discovery data. Its jobs stay in the history under the
     * station name they were created with, but their content is purged (it can hold personal data). Refused
     * while the agent's key is still valid or while it has unfinished jobs (queued, leased, spooled); an
     * administrator waits or cancels them first.
     *
     * The agent's timeouts are applied first, so jobs it left `leased` or `spooled` when it died count as what
     * they have become (back in the queue, or `uncertain`). These are a few port calls, ordered so that a failure
     * midway leaves a revoked agent with less data; calling this again finishes the job. A host that wants one
     * transaction wraps the call.
     */
    public function deleteAgent(AgentId $id): void
    {
        $agent = $this->agents->find($id) ?? throw new AdminException(AdminException::NOT_FOUND, 'unknown agent');
        if (!$agent->isRevoked()) {
            throw new AdminException(AdminException::AGENT_NOT_REVOKED, 'revoke the agent before deleting it');
        }
        $now = $this->now();
        $this->jobs->sweep($id, $now, $agent->settings()->spoolSilenceS());
        if ($this->jobs->hasOpenJobs($id, $now)) {
            throw new AdminException(AdminException::AGENT_HAS_OPEN_JOBS, 'the agent has unfinished jobs; wait or cancel them first');
        }
        $this->jobs->purgeContent($now, $id);
        $this->discovery->delete($id);
        $this->agents->delete($id);
    }

    public function findAgent(AgentId $id): ?Agent
    {
        return $this->agents->find($id);
    }

    /** @return list<Agent> */
    public function agents(): array
    {
        return $this->agents->all();
    }

    /**
     * Picks the printer a job goes to: the given id, or the first printer of the agent with the given role.
     */
    public function resolvePrinterId(AgentId $agentId, ?string $printerId, ?string $role): string
    {
        $agent = $this->agents->find($agentId) ?? throw new AdminException(AdminException::NOT_FOUND, 'unknown agent');
        if (null !== $printerId) {
            return $printerId;
        }
        if (null === $role) {
            throw new AdminException(AdminException::BAD_REQUEST, 'printer_id or printer_role is required');
        }

        $printer = $agent->findPrinterByRole($role);
        if (null === $printer) {
            throw new AdminException(AdminException::BAD_REQUEST, sprintf('agent has no printer with role "%s"', $role));
        }

        return $printer->id;
    }

    public function enqueue(NewJob $new): Job
    {
        $agent = $this->agents->find($new->agentId) ?? throw new AdminException(AdminException::NOT_FOUND, 'unknown agent');
        if (ContentDelivery::Url === $new->delivery && null === $this->config->contentBaseUrl) {
            throw new AdminException(AdminException::BAD_REQUEST, 'delivery url needs PapServerConfig::$contentBaseUrl');
        }
        if (JobFormat::Png === $new->format && !in_array('png', $agent->lastHello()->formats ?? [], true)) {
            throw new AdminException(AdminException::BAD_REQUEST, 'png may only be sent to an agent that listed it');
        }
        try {
            $job = Job::enqueue(JobId::generate(), $new, $this->now(), $agent);
        } catch (\InvalidArgumentException $e) {
            throw new AdminException(AdminException::BAD_REQUEST, $e->getMessage());
        }
        $this->jobs->add($job);
        // new work: the station's next claim is welcome at once, whatever its last one found. Unconditional: the
        // agent read above may predate an empty claim recorded since.
        $this->agents->recordClaimResult($agent->id(), null);

        return $job;
    }

    /** The job with its content (unless purged); for listings use {@see history()}. */
    public function findJob(JobId $id): ?Job
    {
        return $this->jobs->find($id);
    }

    /** One job as the history shows it, without content: for detail views that do not need the label. */
    public function findSummary(JobId $id): ?JobSummary
    {
        return $this->jobs->findSummary($id);
    }

    /** Jobs newest first, filtered and paged, without content; see {@see HistoryQuery}. */
    public function history(HistoryQuery $query = new HistoryQuery()): HistoryPage
    {
        return $this->jobs->history($query);
    }

    /** @return list<JobEvent> the job's timeline, oldest first; empty for an unknown job */
    public function jobEvents(JobId $id): array
    {
        return $this->jobs->events($id);
    }

    /**
     * Drops the content of jobs closed before `$finishedBefore` (final, or queued past `expires_at`); the jobs
     * and their history stay. A time in the future counts as now. Returns how many jobs lost content.
     */
    public function purgeContent(\DateTimeImmutable $finishedBefore): int
    {
        $this->sweepAll();

        return $this->jobs->purgeContent($this->notAfterNow($finishedBefore));
    }

    /**
     * Deletes jobs closed before `$finishedBefore` together with their history. A time in the future counts as
     * now. Returns how many jobs were deleted.
     */
    public function purgeHistory(\DateTimeImmutable $finishedBefore): int
    {
        $this->sweepAll();

        return $this->jobs->purgeHistory($this->notAfterNow($finishedBefore));
    }

    /**
     * Applies the lease and spool timeouts of every station's jobs (5.4.1, section 10), as a claim of that station
     * would: lapsed leases go back to `queued`, silent `spooled` jobs become `uncertain`. One sweep per station.
     */
    public function sweepAll(): void
    {
        $now = $this->now();
        foreach ($this->agents->all() as $agent) {
            $this->jobs->sweep($agent->id(), $now, $agent->settings()->spoolSilenceS());
        }
    }

    /**
     * Cancels a job that is not final; it is never handed out again and later acks get 409 (section 10).
     * With `$onlyIf` the job is cancelled only while it is still in that state, checked under the same row lock
     * as the change (`JobState::Queued`: "cancel unless a station has taken it already"); otherwise CONFLICT.
     */
    public function cancel(JobId $id, ?JobState $onlyIf = null): Job
    {
        $now = $this->now();

        try {
            $job = $this->jobs->modify($id, static function (Job $job) use ($now, $onlyIf): Job {
                if (null !== $onlyIf && $job->state() !== $onlyIf) {
                    throw new AdminException(AdminException::CONFLICT, sprintf('the job is %s, not %s', $job->state()->value, $onlyIf->value));
                }
                $job->cancel($now);

                return $job;
            });
        } catch (ProtocolError $e) {
            throw new AdminException(AdminException::CONFLICT, $e->getMessage());
        }

        return $job ?? throw new AdminException(AdminException::NOT_FOUND, 'unknown job');
    }

    /**
     * Opens a printer scan request that the agent picks up with its next hello or claim (section 14.1).
     */
    public function requestDiscovery(AgentId $agentId): string
    {
        $agent = $this->agents->find($agentId) ?? throw new AdminException(AdminException::NOT_FOUND, 'unknown agent');
        if (false === $agent->announcesDiscovery()) {
            throw new AdminException(AdminException::NOT_SUPPORTED, 'this agent did not announce capabilities.discovery');
        }
        $requestId = 'dr_' . bin2hex(random_bytes(4));
        $this->discovery->open($agentId, $requestId, $this->now());
        $this->agents->recordClaimResult($agentId, null);

        return $requestId;
    }

    public function openDiscoveryRequest(AgentId $agentId): ?string
    {
        return $this->discovery->openRequestId($agentId);
    }

    public function latestDiscovery(AgentId $agentId): ?StoredDiscovery
    {
        return $this->discovery->latest($agentId);
    }

    private function now(): \DateTimeImmutable
    {
        return Time::utc($this->clock->now());
    }

    /** A cut-off in the future would treat queued jobs that can still print as closed. */
    private function notAfterNow(\DateTimeImmutable $time): \DateTimeImmutable
    {
        $now = $this->now();

        return $time < $now ? Time::utc($time) : $now;
    }
}
