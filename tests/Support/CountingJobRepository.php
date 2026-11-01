<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Support;

use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\Claim;
use PrintDock\Server\Domain\HistoryPage;
use PrintDock\Server\Domain\HistoryQuery;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobSummary;
use PrintDock\Server\Port\JobRepository;

/** Delegates to another repository and counts the sweeps, to see how often a long poll takes the write lock. */
final class CountingJobRepository implements JobRepository
{
    public int $sweeps = 0;

    public function __construct(private readonly JobRepository $inner)
    {
    }

    public function add(Job $job): void
    {
        $this->inner->add($job);
    }

    public function find(JobId $id): ?Job
    {
        return $this->inner->find($id);
    }

    public function findSummary(JobId $id): ?JobSummary
    {
        return $this->inner->findSummary($id);
    }

    public function history(HistoryQuery $query): HistoryPage
    {
        return $this->inner->history($query);
    }

    public function events(JobId $id): array
    {
        return $this->inner->events($id);
    }

    public function sweep(AgentId $agentId, \DateTimeImmutable $now, int $spoolSilenceSeconds): void
    {
        ++$this->sweeps;
        $this->inner->sweep($agentId, $now, $spoolSilenceSeconds);
    }

    public function claimNext(AgentId $agentId, array $printerIds, \DateTimeImmutable $now, int $leaseSeconds): ?Claim
    {
        return $this->inner->claimNext($agentId, $printerIds, $now, $leaseSeconds);
    }

    public function modify(JobId $id, callable $change): mixed
    {
        return $this->inner->modify($id, $change);
    }

    public function hasOpenJobs(AgentId $agentId, \DateTimeImmutable $now): bool
    {
        return $this->inner->hasOpenJobs($agentId, $now);
    }

    public function purgeContent(\DateTimeImmutable $closedBy, ?AgentId $agentId = null): int
    {
        return $this->inner->purgeContent($closedBy, $agentId);
    }

    public function purgeHistory(\DateTimeImmutable $closedBy): int
    {
        return $this->inner->purgeHistory($closedBy);
    }

    public function earliestNotBefore(AgentId $agentId, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $this->inner->earliestNotBefore($agentId, $now);
    }
}
