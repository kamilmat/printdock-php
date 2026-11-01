<?php

declare(strict_types=1);

namespace PrintDock\Server\Adapter\InMemory;

use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\Claim;
use PrintDock\Server\Domain\HistoryPage;
use PrintDock\Server\Domain\HistoryQuery;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobEvent;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobState;
use PrintDock\Server\Domain\JobSummary;
use PrintDock\Server\Domain\LeaseToken;
use PrintDock\Server\Port\JobRepository;

/**
 * Jobs in a PHP array, in insertion order (= "oldest first"). Works on copies so that a failing `modify`
 * leaves no trace, as a rolled-back transaction would; events are appended only once a change succeeded.
 * Single-process only: atomicity is trivially true here, a SQL adapter has to earn it with row locks.
 */
final class InMemoryJobRepository implements JobRepository
{
    /** @var array<string, Job> */
    private array $jobs = [];

    /** @var array<string, int> job id => insertion sequence */
    private array $seqs = [];

    /** @var list<JobEvent> */
    private array $events = [];

    private int $lastSeq = 0;

    private int $lastEventSeq = 0;

    public function add(Job $job): void
    {
        $this->seqs[$job->id()->value] = ++$this->lastSeq;
        $this->store($job);
    }

    public function find(JobId $id): ?Job
    {
        $job = $this->jobs[$id->value] ?? null;

        return null === $job ? null : clone $job;
    }

    public function findSummary(JobId $id): ?JobSummary
    {
        $job = $this->jobs[$id->value] ?? null;

        return null === $job ? null : JobSummary::fromJob($job, $this->seqs[$id->value]);
    }

    public function history(HistoryQuery $query): HistoryPage
    {
        $candidates = [];
        foreach (array_reverse($this->jobs) as $id => $job) {
            $summary = JobSummary::fromJob($job, $this->seqs[$id]);
            if ($query->matches($summary)) {
                $candidates[] = $summary;
                if (count($candidates) > $query->limit) {
                    break;
                }
            }
        }

        return HistoryPage::fromCandidates($candidates, $query->limit);
    }

    public function events(JobId $id): array
    {
        return array_values(array_filter($this->events, static fn (JobEvent $e): bool => $e->jobId->equals($id)));
    }

    public function sweep(AgentId $agentId, \DateTimeImmutable $now, int $spoolSilenceSeconds): void
    {
        foreach ($this->jobs as $job) {
            if ($job->agentId()->equals($agentId)) {
                $job->applyTimeouts($now, $spoolSilenceSeconds);
                $this->appendEvents($job);
            }
        }
    }

    public function claimNext(AgentId $agentId, array $printerIds, \DateTimeImmutable $now, int $leaseSeconds): ?Claim
    {
        foreach ($this->jobs as $job) {
            if ($job->isRedeliverableTo($agentId, $printerIds)) {
                $job->redeliver($now, $leaseSeconds);
                $this->appendEvents($job);

                return new Claim(clone $job, true);
            }
        }
        foreach ($this->jobs as $job) {
            if ($job->isClaimableFor($agentId, $printerIds, $now)) {
                $job->lease(LeaseToken::generate(), $now, $leaseSeconds);
                $this->appendEvents($job);

                return new Claim(clone $job, false);
            }
        }

        return null;
    }

    public function modify(JobId $id, callable $change): mixed
    {
        $stored = $this->jobs[$id->value] ?? null;
        if (null === $stored) {
            return null;
        }
        $copy = clone $stored;
        $result = $change($copy);
        $this->store($copy);

        return $result;
    }

    public function hasOpenJobs(AgentId $agentId, \DateTimeImmutable $now): bool
    {
        foreach ($this->jobs as $job) {
            if ($job->agentId()->equals($agentId) && !$job->state()->isFinal() && !$job->isClosedBy($now)) {
                return true;
            }
        }

        return false;
    }

    public function purgeContent(\DateTimeImmutable $closedBy, ?AgentId $agentId = null): int
    {
        $purged = 0;
        foreach ($this->jobs as $job) {
            if ((null === $agentId || $job->agentId()->equals($agentId)) && !$job->contentPurged() && $job->isClosedBy($closedBy)) {
                $job->purgeContent();
                ++$purged;
            }
        }

        return $purged;
    }

    public function purgeHistory(\DateTimeImmutable $closedBy): int
    {
        $deleted = [];
        foreach ($this->jobs as $key => $job) {
            if ($job->isClosedBy($closedBy)) {
                $deleted[] = $job->id();
                unset($this->jobs[$key], $this->seqs[$key]);
            }
        }
        $this->events = array_values(array_filter(
            $this->events,
            static fn (JobEvent $e): bool => [] === array_filter($deleted, static fn (JobId $id): bool => $id->equals($e->jobId)),
        ));

        return count($deleted);
    }

    public function earliestNotBefore(AgentId $agentId, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $earliest = null;
        foreach ($this->jobs as $job) {
            if ($job->agentId()->equals($agentId) && JobState::Queued === $job->state() && $job->notBefore() > $now
                && (null === $earliest || $job->notBefore() < $earliest)) {
                $earliest = $job->notBefore();
            }
        }

        return $earliest;
    }

    private function store(Job $job): void
    {
        $this->appendEvents($job);
        $this->jobs[$job->id()->value] = clone $job;
    }

    private function appendEvents(Job $job): void
    {
        foreach ($job->pullEvents() as $event) {
            $this->events[] = $event->withSeq(++$this->lastEventSeq);
        }
    }
}
