<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * One step of a job's durable history, written by the repository in the same transaction as the state change
 * it describes. Append-only: never updated, deleted only together with its job.
 *
 * `outcome`, `reason`, `detail`, `spoolJobId` and `late` are set for {@see JobEventType::Acked} only. The job's
 * title is deliberately not copied here (it may be sensitive); it stays on the job. `seq` is null until stored.
 */
final class JobEvent
{
    public function __construct(
        public readonly JobId $jobId,
        public readonly AgentId $agentId,
        public readonly JobEventType $type,
        public readonly JobState $stateAfter,
        public readonly int $attempt,
        public readonly \DateTimeImmutable $at,
        public readonly ?Outcome $outcome = null,
        public readonly ?ReasonCode $reason = null,
        public readonly ?string $detail = null,
        public readonly ?string $spoolJobId = null,
        public readonly bool $late = false,
        public readonly ?int $seq = null,
    ) {
    }

    public function withSeq(int $seq): self
    {
        return new self(
            $this->jobId,
            $this->agentId,
            $this->type,
            $this->stateAfter,
            $this->attempt,
            $this->at,
            $this->outcome,
            $this->reason,
            $this->detail,
            $this->spoolJobId,
            $this->late,
            $seq,
        );
    }
}
