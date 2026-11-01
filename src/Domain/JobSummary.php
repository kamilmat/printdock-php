<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * A job as the history shows it: everything except the content and the lease tokens, so a listing never loads
 * label bytes (a PDF can weigh megabytes). `seq` is the storage order and the history's paging cursor.
 */
final class JobSummary
{
    /**
     * @param array<string, mixed>|null $options
     * @param array<string, mixed>|null $reference
     */
    public function __construct(
        public readonly int $seq,
        public readonly JobId $id,
        public readonly AgentId $agentId,
        public readonly ?string $agentName,
        public readonly string $printerId,
        public readonly ?string $printerName,
        public readonly ?string $printerRole,
        public readonly JobFormat $format,
        public readonly int $contentSize,
        public readonly bool $contentPurged,
        public readonly ContentDelivery $delivery,
        public readonly ?array $options,
        public readonly ?string $title,
        public readonly bool $titleSensitive,
        public readonly ?array $reference,
        public readonly JobState $state,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $notBefore,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly int $attempt,
        public readonly ?\DateTimeImmutable $leaseUntil,
        public readonly ?\DateTimeImmutable $leasedAt,
        public readonly ?\DateTimeImmutable $lastContactAt,
        public readonly ?\DateTimeImmutable $finishedAt,
        public readonly ?Outcome $outcome,
        public readonly ?ReasonCode $reason,
        public readonly ?string $detail,
        public readonly ?string $spoolJobId,
        public readonly bool $late,
        public readonly bool $redeliverAfterLeaseExpiry = true,
    ) {
    }

    /** The job's content is not read here, so a repository may hand in a job loaded without it. */
    public static function fromJob(Job $job, int $seq): self
    {
        return new self(
            $seq,
            $job->id(),
            $job->agentId(),
            $job->agentName(),
            $job->printerId(),
            $job->printerName(),
            $job->printerRole(),
            $job->format(),
            $job->contentSize(),
            $job->contentPurged(),
            $job->delivery(),
            $job->options(),
            $job->title(),
            $job->titleSensitive(),
            $job->reference(),
            $job->state(),
            $job->createdAt(),
            $job->notBefore(),
            $job->expiresAt(),
            $job->attempt(),
            $job->leaseUntil(),
            $job->leasedAt(),
            $job->lastContactAt(),
            $job->finishedAt(),
            $job->outcome(),
            $job->reason(),
            $job->detail(),
            $job->spoolJobId(),
            $job->wasLate(),
            $job->redeliversAfterLeaseExpiry(),
        );
    }

    /** As {@see Job::reportedStatus()}: `expired` for a queued or leased job past `expires_at`. */
    public function reportedStatus(\DateTimeImmutable $now): string
    {
        if (in_array($this->state, [JobState::Queued, JobState::Leased], true) && $now > $this->expiresAt) {
            return 'expired';
        }

        return $this->state->value;
    }
}
