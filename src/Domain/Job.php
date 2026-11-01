<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * A print job and every protocol rule that decides what may happen to it: leasing, redelivery, lease expiry,
 * lease extension and acknowledgement (sections 5, 6 and 10).
 *
 * Repositories must apply these methods rather than re-implement the rules, so that every adapter behaves the
 * same. The one place that cannot is selection by SQL; there the adapter filters candidates and still calls
 * {@see isClaimableFor()} / {@see isRedeliverableTo()} on the loaded job.
 *
 * Every state change records a {@see JobEvent}; the repository takes them with {@see pullEvents()} and stores
 * them in the same transaction as the job, so the history can never disagree with the state. `agent_name` and
 * `printer_name` are snapshots taken when the job is created, so the history survives renames and deleted
 * stations. Once the content is purged ({@see purgeContent()}) the job keeps its metadata and `content_size`.
 *
 * A repository may load a job without its content (an ack or a sweep never needs the label bytes) and hand
 * {@see fromData()} a loader instead; {@see content()} then reads it on first use. In `JobData` a null
 * `content` means "not loaded".
 *
 * `redeliver_after_lease_expiry` is optional in `JobData` (true when absent, the protocol's default); see
 * {@see NewJob} and {@see applyTimeouts()}.
 *
 * @phpstan-type JobData array{
 *     id: string, agent_id: string, printer_id: string, printer_role: ?string, format: string, content: ?string,
 *     delivery: string, options: ?array<string, mixed>, title: ?string, title_sensitive: bool,
 *     reference: ?array<string, mixed>, state: string, created_at: \DateTimeImmutable,
 *     not_before: \DateTimeImmutable, expires_at: \DateTimeImmutable, attempt: int, lease_token: ?string,
 *     lease_tokens: list<string>, lease_until: ?\DateTimeImmutable, leased_at: ?\DateTimeImmutable,
 *     last_contact_at: ?\DateTimeImmutable, finished_at: ?\DateTimeImmutable, outcome: ?string, reason: ?string,
 *     detail: ?string, spool_job_id: ?string, late: bool, agent_name: ?string, printer_name: ?string,
 *     content_size: int, content_purged: bool, redeliver_after_lease_expiry?: bool
 * }
 */
final class Job
{
    /** @var list<JobEvent> recorded, not yet stored */
    private array $events = [];

    /** @var (\Closure(): string)|null reads the content of a job loaded without it */
    private ?\Closure $contentLoader = null;

    /**
     * @param array<string, mixed>|null $options
     * @param array<string, mixed>|null $reference
     * @param list<LeaseToken>          $leaseTokens every token ever issued, current one last
     */
    private function __construct(
        private readonly JobId $id,
        private readonly AgentId $agentId,
        private readonly string $printerId,
        private readonly ?string $printerRole,
        private readonly JobFormat $format,
        private ?string $content,
        private readonly ContentDelivery $delivery,
        private readonly ?array $options,
        private readonly ?string $title,
        private readonly bool $titleSensitive,
        private readonly ?array $reference,
        private JobState $state,
        private readonly \DateTimeImmutable $createdAt,
        private readonly \DateTimeImmutable $notBefore,
        private readonly \DateTimeImmutable $expiresAt,
        private int $attempt,
        private ?LeaseToken $leaseToken,
        private array $leaseTokens,
        private ?\DateTimeImmutable $leaseUntil,
        private ?\DateTimeImmutable $leasedAt,
        private ?\DateTimeImmutable $lastContactAt,
        private ?\DateTimeImmutable $finishedAt,
        private ?Outcome $outcome,
        private ?ReasonCode $reason,
        private ?string $detail,
        private ?string $spoolJobId,
        private bool $late,
        private readonly ?string $agentName,
        private readonly ?string $printerName,
        private readonly int $contentSize,
        private bool $contentPurged,
        private readonly bool $redeliverAfterLeaseExpiry = true,
    ) {
    }

    /**
     * @param Agent|null $agent the target agent, for the name snapshots and the printer's PDF defaults
     *
     * @throws \InvalidArgumentException for invalid PDF options ({@see PdfOptions::resolve()})
     */
    public static function enqueue(JobId $id, NewJob $new, \DateTimeImmutable $now, ?Agent $agent = null): self
    {
        $printer = $agent?->findPrinter($new->printerId);
        $job = new self(
            $id,
            $new->agentId,
            $new->printerId,
            $new->printerRole,
            $new->format,
            $new->content,
            $new->delivery,
            JobFormat::Pdf === $new->format ? PdfOptions::resolve($new->options, $printer) : null,
            $new->title,
            $new->titleSensitive,
            $new->reference,
            JobState::Queued,
            $now,
            Time::add($now, $new->notBeforeInSeconds),
            Time::add($now, $new->expiresInSeconds),
            0,
            null,
            [],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            false,
            $agent?->name(),
            $printer?->name,
            strlen($new->content),
            false,
            $new->redeliverAfterLeaseExpiry,
        );
        $job->record(JobEventType::Enqueued, $now);

        return $job;
    }

    /**
     * @param JobData                  $d
     * @param (\Closure(): string)|null $contentLoader required when `$d['content']` is null
     */
    public static function fromData(array $d, ?\Closure $contentLoader = null): self
    {
        if (null === $d['content'] && null === $contentLoader) {
            throw new \InvalidArgumentException('a job loaded without content needs a content loader');
        }
        $job = new self(
            JobId::fromString($d['id']),
            AgentId::fromString($d['agent_id']),
            $d['printer_id'],
            $d['printer_role'],
            JobFormat::from($d['format']),
            $d['content'],
            ContentDelivery::from($d['delivery']),
            $d['options'],
            $d['title'],
            $d['title_sensitive'],
            $d['reference'],
            JobState::from($d['state']),
            $d['created_at'],
            $d['not_before'],
            $d['expires_at'],
            $d['attempt'],
            null === $d['lease_token'] ? null : LeaseToken::fromString($d['lease_token']),
            array_map(LeaseToken::fromString(...), $d['lease_tokens']),
            $d['lease_until'],
            $d['leased_at'],
            $d['last_contact_at'],
            $d['finished_at'],
            null === $d['outcome'] ? null : Outcome::from($d['outcome']),
            null === $d['reason'] ? null : ReasonCode::fromString($d['reason']),
            $d['detail'],
            $d['spool_job_id'],
            $d['late'],
            $d['agent_name'],
            $d['printer_name'],
            $d['content_size'],
            $d['content_purged'],
            $d['redeliver_after_lease_expiry'] ?? true,
        );
        $job->contentLoader = null === $d['content'] ? $contentLoader : null;

        return $job;
    }

    /**
     * The stored form; `content` is null when the job was loaded without it and has not read it since.
     *
     * @return JobData
     */
    public function toData(): array
    {
        return [
            'id' => $this->id->value, 'agent_id' => $this->agentId->value, 'printer_id' => $this->printerId,
            'printer_role' => $this->printerRole, 'format' => $this->format->value, 'content' => $this->content,
            'delivery' => $this->delivery->value, 'options' => $this->options, 'title' => $this->title,
            'title_sensitive' => $this->titleSensitive, 'reference' => $this->reference,
            'state' => $this->state->value, 'created_at' => $this->createdAt, 'not_before' => $this->notBefore,
            'expires_at' => $this->expiresAt, 'attempt' => $this->attempt,
            'lease_token' => $this->leaseToken?->value,
            'lease_tokens' => array_map(static fn (LeaseToken $t): string => $t->value, $this->leaseTokens),
            'lease_until' => $this->leaseUntil, 'leased_at' => $this->leasedAt,
            'last_contact_at' => $this->lastContactAt, 'finished_at' => $this->finishedAt,
            'outcome' => $this->outcome?->value, 'reason' => $this->reason?->value, 'detail' => $this->detail,
            'spool_job_id' => $this->spoolJobId, 'late' => $this->late, 'agent_name' => $this->agentName,
            'printer_name' => $this->printerName, 'content_size' => $this->contentSize,
            'content_purged' => $this->contentPurged,
            'redeliver_after_lease_expiry' => $this->redeliverAfterLeaseExpiry,
        ];
    }

    public function id(): JobId
    {
        return $this->id;
    }

    public function agentId(): AgentId
    {
        return $this->agentId;
    }

    public function printerId(): string
    {
        return $this->printerId;
    }

    public function printerRole(): ?string
    {
        return $this->printerRole;
    }

    public function format(): JobFormat
    {
        return $this->format;
    }

    /** The label bytes; `''` once purged. Read from storage on first use when the job was loaded without them. */
    public function content(): string
    {
        if (null === $this->content) {
            $this->content = $this->contentLoader instanceof \Closure ? ($this->contentLoader)() : '';
            $this->contentLoader = null;
        }

        return $this->content;
    }

    public function contentSha256(): string
    {
        return hash('sha256', $this->content());
    }

    /** Size of the content as enqueued; kept after the content is purged. */
    public function contentSize(): int
    {
        return $this->contentSize;
    }

    public function contentPurged(): bool
    {
        return $this->contentPurged;
    }

    public function agentName(): ?string
    {
        return $this->agentName;
    }

    public function printerName(): ?string
    {
        return $this->printerName;
    }

    public function delivery(): ContentDelivery
    {
        return $this->delivery;
    }

    /** @return array<string, mixed>|null */
    public function options(): ?array
    {
        return $this->options;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function titleSensitive(): bool
    {
        return $this->titleSensitive;
    }

    /** @return array<string, mixed>|null */
    public function reference(): ?array
    {
        return $this->reference;
    }

    public function state(): JobState
    {
        return $this->state;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function notBefore(): \DateTimeImmutable
    {
        return $this->notBefore;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function leaseToken(): ?LeaseToken
    {
        return $this->leaseToken;
    }

    public function leaseUntil(): ?\DateTimeImmutable
    {
        return $this->leaseUntil;
    }

    public function leasedAt(): ?\DateTimeImmutable
    {
        return $this->leasedAt;
    }

    public function lastContactAt(): ?\DateTimeImmutable
    {
        return $this->lastContactAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function outcome(): ?Outcome
    {
        return $this->outcome;
    }

    public function reason(): ?ReasonCode
    {
        return $this->reason;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }

    public function spoolJobId(): ?string
    {
        return $this->spoolJobId;
    }

    /** False for "at most once" delivery: an expired lease closes the job as `uncertain` ({@see applyTimeouts()}). */
    public function redeliversAfterLeaseExpiry(): bool
    {
        return $this->redeliverAfterLeaseExpiry;
    }

    public function wasLate(): bool
    {
        return $this->late;
    }

    /** The status a reader sees: `expired` is computed for jobs that are still queued or leased (section 10). */
    public function reportedStatus(\DateTimeImmutable $now): string
    {
        if (in_array($this->state, [JobState::Queued, JobState::Leased], true) && $now > $this->expiresAt) {
            return 'expired';
        }

        return $this->state->value;
    }

    /**
     * Closed by `$time`: final at or before it, or still `queued` with `expires_at` at or before it (such a job
     * can never be delivered, section 5). Retention and station deletion act on closed jobs only.
     */
    public function isClosedBy(\DateTimeImmutable $time): bool
    {
        return ($this->state->isFinal() && null !== $this->finishedAt && $this->finishedAt <= $time)
            || (JobState::Queued === $this->state && $this->expiresAt <= $time);
    }

    /** Drops the content bytes (they may hold personal data); metadata, size and history stay. */
    public function purgeContent(): void
    {
        $this->content = '';
        $this->contentLoader = null;
        $this->contentPurged = true;
    }

    /**
     * The events recorded since the last call, oldest first; the repository stores them with the job.
     *
     * @return list<JobEvent>
     */
    public function pullEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * Redelivery rule (5.4.2): only the agent's own `leased` jobs on the requested printers; never `spooled`.
     *
     * @param list<string> $printerIds
     */
    public function isRedeliverableTo(AgentId $agentId, array $printerIds): bool
    {
        return JobState::Leased === $this->state
            && $this->agentId->equals($agentId)
            && in_array($this->printerId, $printerIds, true);
    }

    /**
     * New-work rule (5.4.3 and 5.4.4): queued, on a requested printer, due, and not expired.
     *
     * @param list<string> $printerIds
     */
    public function isClaimableFor(AgentId $agentId, array $printerIds, \DateTimeImmutable $now): bool
    {
        return JobState::Queued === $this->state
            && $this->agentId->equals($agentId)
            && in_array($this->printerId, $printerIds, true)
            && $this->notBefore <= $now
            && $now < $this->expiresAt;
    }

    /** Repeats a lease: same token and attempt, lease refreshed (5.4.2). */
    public function redeliver(\DateTimeImmutable $now, int $leaseSeconds): void
    {
        $this->leaseUntil = Time::add($now, $leaseSeconds);
        $this->lastContactAt = $now;
        $this->record(JobEventType::Redelivered, $now);
    }

    /** Starts a new lease: new token, attempt + 1 (5.4.3). */
    public function lease(LeaseToken $token, \DateTimeImmutable $now, int $leaseSeconds): void
    {
        $this->state = JobState::Leased;
        ++$this->attempt;
        $this->leaseToken = $token;
        $this->leaseTokens[] = $token;
        $this->leasedAt = $now;
        $this->lastContactAt = $now;
        $this->leaseUntil = Time::add($now, $leaseSeconds);
        $this->record(JobEventType::Leased, $now);
    }

    /**
     * Sweep rule (5.4.1 and section 10): an expired lease returns the job to `queued` (attempt kept); a `spooled`
     * job never returns, it becomes `uncertain` after `$spoolSilenceSeconds` without contact. A job enqueued
     * without redelivery after lease expiry ({@see NewJob}) is not handed out again either: its expired lease
     * closes it as `uncertain` with reason {@see ReasonCode::LEASE_EXPIRED}. A final ack from the agent still
     * replaces that guess (6.4.3).
     */
    public function applyTimeouts(\DateTimeImmutable $now, int $spoolSilenceSeconds): void
    {
        if (JobState::Leased === $this->state && null !== $this->leaseUntil && $now > $this->leaseUntil) {
            $this->leaseUntil = null;
            if ($this->redeliverAfterLeaseExpiry) {
                $this->state = JobState::Queued;
            } else {
                $this->state = JobState::Uncertain;
                $this->finishedAt = $now;
                $this->reason = ReasonCode::fromString(ReasonCode::LEASE_EXPIRED);
            }
            $this->record(JobEventType::LeaseExpired, $now);
        } elseif (JobState::Spooled === $this->state && null !== $this->lastContactAt
            && Time::secondsBetween($this->lastContactAt, $now) > $spoolSilenceSeconds) {
            $this->state = JobState::Uncertain;
            $this->finishedAt = $now;
            $this->record(JobEventType::SpoolSilence, $now);
        }
    }

    public function hasIssued(LeaseToken $token): bool
    {
        foreach ($this->leaseTokens as $issued) {
            if ($issued->equals($token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lease extension (5.6). The caller has already applied {@see applyTimeouts()}.
     *
     * @throws ProtocolError 403 for a token never issued, 409 when the job is not leased with this token
     */
    public function extend(LeaseToken $token, \DateTimeImmutable $now, int $leaseSeconds): \DateTimeImmutable
    {
        if (!$this->hasIssued($token)) {
            throw ProtocolError::badLeaseToken();
        }
        if (JobState::Leased !== $this->state || null === $this->leaseToken || !$this->leaseToken->equals($token)) {
            throw ProtocolError::conflict('job is not leased with this token', $this->reportedStatus($now));
        }
        $this->leaseUntil = Time::add($now, $leaseSeconds);
        $this->lastContactAt = $now;

        return $this->leaseUntil;
    }

    /**
     * Acknowledgement rules (6.4). The caller has already applied {@see applyTimeouts()}.
     *
     * @throws ProtocolError 403 for a token never issued, 409 when the job is closed differently or cancelled
     */
    public function acknowledge(
        LeaseToken $token,
        Outcome $outcome,
        ?ReasonCode $reason,
        ?string $detail,
        ?string $spoolJobId,
        \DateTimeImmutable $now,
    ): AckResult {
        if (!$this->hasIssued($token)) {
            throw ProtocolError::badLeaseToken();
        }
        $target = $outcome->resultingState();
        $late = JobState::Queued === $this->state
            || $now > $this->expiresAt
            // a spooled job is no longer under its lease (never redelivered, 5.4): late only after expires_at
            || (JobState::Spooled !== $this->state && null !== $this->leaseUntil && $now > $this->leaseUntil)
            || null === $this->leaseToken
            || !$this->leaseToken->equals($token);

        if ($this->state->isFinal()) {
            if (JobState::Cancelled === $this->state) {
                throw ProtocolError::conflict('job was cancelled', 'cancelled');
            }
            if ($this->state === $target) {
                return new AckResult($this->state, $late);
            }
            if (JobState::Uncertain !== $this->state || !in_array($target, [JobState::Printed, JobState::Failed], true)) {
                throw ProtocolError::conflict('job already closed as ' . $this->state->value, $this->state->value);
            }
            // An agent that knows beats a server that guessed (6.4.3).
            $late = true;
        }

        // A repeated `spooled` ack only refreshes contact unless it says something new; then it is history too.
        $changed = $this->state !== $target || $this->outcome !== $outcome || $this->reason?->value !== $reason?->value
            || $this->detail !== $detail || $this->spoolJobId !== $spoolJobId || $this->late !== $late;
        $this->lastContactAt = $now;
        $this->detail = $detail;
        $this->outcome = $outcome;
        $this->reason = $reason;
        $this->spoolJobId = $spoolJobId;
        $this->late = $late;
        $this->state = $target;
        if ($target->isFinal()) {
            $this->finishedAt = $now;
        }
        if ($changed) {
            $this->record(JobEventType::Acked, $now);
        }

        return new AckResult($target, $late);
    }

    /**
     * Operator cancellation: never handed out again, later acks answer 409 (section 10).
     *
     * @throws ProtocolError when the job is already final
     */
    public function cancel(\DateTimeImmutable $now): void
    {
        if ($this->state->isFinal()) {
            throw ProtocolError::conflict('job already final', $this->state->value);
        }
        $this->state = JobState::Cancelled;
        $this->finishedAt = $now;
        $this->record(JobEventType::Cancelled, $now);
    }

    private function record(JobEventType $type, \DateTimeImmutable $at): void
    {
        $acked = JobEventType::Acked === $type;
        $this->events[] = new JobEvent(
            $this->id,
            $this->agentId,
            $type,
            $this->state,
            $this->attempt,
            $at,
            $acked ? $this->outcome : null,
            $acked ? $this->reason : null,
            $acked ? $this->detail : null,
            $acked ? $this->spoolJobId : null,
            $acked && $this->late,
        );
    }
}
