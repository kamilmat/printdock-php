<?php

declare(strict_types=1);

namespace PrintDock\Server\Port;

use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\Claim;
use PrintDock\Server\Domain\HistoryPage;
use PrintDock\Server\Domain\HistoryQuery;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobEvent;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobSummary;

/**
 * Persistence of jobs and the two operations that must be atomic. Implemented by the host.
 *
 * Contract for SQL adapters: {@see claimNext()} and {@see modify()} run in one transaction and lock the rows
 * they read (`SELECT ... FOR UPDATE`, and `FOR UPDATE SKIP LOCKED` for the candidates of a new lease on
 * MySQL 8 / PostgreSQL), so that two concurrent claims can never lease the same job and an ack never races a
 * claim. If the caller is already inside a transaction, join it instead of opening another.
 * Selecting is the adapter's job, the rules are not: load the job and let {@see Job} decide and mutate it
 * ({@see Job::isRedeliverableTo()}, {@see Job::isClaimableFor()}, {@see Job::lease()}).
 *
 * History: whatever writes a job ({@see add()}, {@see sweep()}, {@see claimNext()}, {@see modify()}) also
 * appends the job's {@see Job::pullEvents()} to the event log in the same transaction, oldest first. The log is
 * append-only; events go away only with their job ({@see purgeHistory()}). Listings ({@see history()},
 * {@see findSummary()}) never read the content column, and neither need {@see modify()} nor {@see sweep()}: a SQL
 * adapter loads those jobs without it and passes {@see Job::fromData()} a loader, so an ack does not drag a
 * multi-megabyte PDF through PHP. {@see find()} and {@see claimNext()} (which delivers it) return it.
 */
interface JobRepository
{
    /** Stores a new job and its recorded events atomically. */
    public function add(Job $job): void;

    /** The job with its content (unless purged). */
    public function find(JobId $id): ?Job;

    /** One job as the history lists it, without content; null for an unknown job. */
    public function findSummary(JobId $id): ?JobSummary;

    /** Jobs matching the query, newest first (by insertion order), without content; see {@see HistoryQuery}. */
    public function history(HistoryQuery $query): HistoryPage;

    /** @return list<JobEvent> the job's history, oldest first, with `seq` set; empty for an unknown job */
    public function events(JobId $id): array;

    /**
     * Sweep (5.4.1): return the agent's `leased` jobs whose lease ran out to `queued`, and turn `spooled` jobs
     * silent for longer than `$spoolSilenceSeconds` into `uncertain`. Equivalent to calling
     * {@see Job::applyTimeouts()} on each of the agent's jobs and storing what changed with its events.
     */
    public function sweep(AgentId $agentId, \DateTimeImmutable $now, int $spoolSilenceSeconds): void;

    /**
     * Atomically picks the job to hand out (5.4.2, 5.4.3): the agent's oldest `leased` job on one of the
     * printers (redelivery, lease refreshed), otherwise its oldest claimable `queued` job (new lease).
     * Oldest means insertion order. Returns null when there is nothing to hand out.
     *
     * @param list<string> $printerIds only ids the agent really has
     */
    public function claimNext(AgentId $agentId, array $printerIds, \DateTimeImmutable $now, int $leaseSeconds): ?Claim;

    /**
     * Loads the job with a write lock, runs `$change` on it and persists the result, atomically.
     * When `$change` throws, nothing is saved and the exception propagates (so ack conflicts roll back).
     * Returns null, without calling `$change`, when the job does not exist.
     *
     * @template T
     *
     * @param callable(Job): T $change
     *
     * @return T|null
     */
    public function modify(JobId $id, callable $change): mixed;

    /**
     * True when the agent has a job that is `leased` or `spooled`, or `queued` and not yet past `expires_at`
     * (stored state, no sweep applied). A queued job past its deadline can never be delivered (section 5), so it
     * does not hold the agent.
     */
    public function hasOpenJobs(AgentId $agentId, \DateTimeImmutable $now): bool;

    /**
     * Drops the content of jobs closed by `$closedBy` ({@see Job::isClosedBy()}, {@see Job::purgeContent()}),
     * only the agent's when `$agentId` is given. Jobs and events stay. Returns how many jobs lost content.
     * The caller never passes a time in the future.
     */
    public function purgeContent(\DateTimeImmutable $closedBy, ?AgentId $agentId = null): int;

    /** Deletes jobs closed by `$closedBy` together with their events; returns how many jobs. */
    public function purgeHistory(\DateTimeImmutable $closedBy): int;

    /** The earliest `not_before` still in the future among the agent's queued jobs; drives `Retry-After`. */
    public function earliestNotBefore(AgentId $agentId, \DateTimeImmutable $now): ?\DateTimeImmutable;
}
