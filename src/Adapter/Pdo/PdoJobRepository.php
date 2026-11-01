<?php

declare(strict_types=1);

namespace PrintDock\Server\Adapter\Pdo;

use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\Claim;
use PrintDock\Server\Domain\HistoryPage;
use PrintDock\Server\Domain\HistoryQuery;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobEvent;
use PrintDock\Server\Domain\JobEventType;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobState;
use PrintDock\Server\Domain\JobSummary;
use PrintDock\Server\Domain\LeaseToken;
use PrintDock\Server\Domain\Outcome;
use PrintDock\Server\Domain\ReasonCode;
use PrintDock\Server\Port\JobRepository;

/**
 * Jobs in `printdock_jobs`, their history in `printdock_job_events` (SQLite or MySQL).
 *
 * `claimNext`, `modify` and `sweep` run in one transaction and lock what they read: `FOR UPDATE SKIP LOCKED` on
 * the candidates of a new lease, plain `FOR UPDATE` when loading a job to change it. SQLite serialises writers
 * with `BEGIN IMMEDIATE`. SQL only selects the candidate; the rules are applied by the {@see Job} methods, and
 * the events they record are inserted in the same transaction. The content column is written once, on insert
 * (and emptied by a purge); updates and listings never touch it, and only {@see find()} and {@see claimNext()}
 * (which delivers it) read it. `modify` and `sweep` load jobs without it and give them a loader instead.
 *
 * @phpstan-import-type JobData from Job
 */
final class PdoJobRepository implements JobRepository
{
    private readonly PdoSupport $db;

    public function __construct(\PDO $pdo)
    {
        $this->db = new PdoSupport($pdo);
    }

    /** Every column but the content: what history listings select. */
    private const SUMMARY_COLUMNS = 'seq, job_id, agent_id, printer_id, printer_role, format, delivery, options, title,'
        . ' title_sensitive, reference, state, created_at, not_before, expires_at, attempt, lease_token, lease_tokens,'
        . ' lease_until, leased_at, last_contact_at, finished_at, outcome, reason, detail, spool_job_id, late,'
        . ' agent_name, printer_name, content_size, content_purged, redeliver_after_lease_expiry';

    /** SQL form of {@see Job::isClosedBy()}; binds the time twice. */
    private const CLOSED_BY = "((state IN ('printed', 'failed', 'uncertain', 'cancelled') AND finished_at <= ?)"
        . " OR (state = 'queued' AND expires_at <= ?))";

    public function add(Job $job): void
    {
        // pulled once, outside the transaction, so a retried transaction still inserts them
        $events = $job->pullEvents();
        $this->db->transactional(function () use ($job, $events): void {
            $row = $this->toRow($job->toData());
            $columns = array_keys($row);
            $this->db->run(
                sprintf(
                    'INSERT INTO printdock_jobs (%s) VALUES (%s)',
                    implode(', ', $columns),
                    implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
                ),
                $row,
                'content',
            );
            $this->insertEvents($events);
        });
    }

    public function find(JobId $id): ?Job
    {
        $row = $this->db->fetchRow('SELECT * FROM printdock_jobs WHERE job_id = ?', [$id->value]);

        return null === $row ? null : $this->hydrate($row);
    }

    public function findSummary(JobId $id): ?JobSummary
    {
        $row = $this->db->fetchRow('SELECT ' . self::SUMMARY_COLUMNS . ' FROM printdock_jobs WHERE job_id = ?', [$id->value]);

        return null === $row ? null : JobSummary::fromJob($this->hydrate($row), PdoSupport::int($row['seq']));
    }

    public function history(HistoryQuery $query): HistoryPage
    {
        $where = [];
        $params = [];
        if (null !== $query->before) {
            $where[] = 'seq < ?';
            $params[] = $query->before;
        }
        if (null !== $query->agentId) {
            $where[] = 'agent_id = ?';
            $params[] = $query->agentId->value;
        }
        if (null !== $query->printerId) {
            $where[] = 'printer_id = ?';
            $params[] = $query->printerId;
        }
        if ([] !== $query->states) {
            $where[] = 'state IN (' . implode(', ', array_fill(0, count($query->states), '?')) . ')';
            array_push($params, ...array_map(static fn (JobState $s): string => $s->value, $query->states));
        }
        if (null !== $query->createdFrom) {
            $where[] = 'created_at >= ?';
            $params[] = PdoSupport::formatTime($query->createdFrom);
        }
        if (null !== $query->createdTo) {
            $where[] = 'created_at < ?';
            $params[] = PdoSupport::formatTime($query->createdTo);
        }
        if (null !== $query->text) {
            $like = '%' . strtr($query->text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $text = ["(title_sensitive = 0 AND title LIKE ? ESCAPE '!')", "source_ref LIKE ? ESCAPE '!'"];
            array_push($params, $like, $like);
            // job ids are ASCII; MySQL refuses to compare non-ASCII text with the ascii_bin column at all
            if (1 !== preg_match('/[^\x00-\x7F]/', $query->text)) {
                $text[] = "job_id LIKE ? ESCAPE '!'";
                $params[] = $like;
            }
            $where[] = '(' . implode(' OR ', $text) . ')';
        }
        $rows = $this->db->fetchRows(
            'SELECT ' . self::SUMMARY_COLUMNS . ' FROM printdock_jobs'
            . ([] === $where ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY seq DESC LIMIT ' . ($query->limit + 1),
            $params,
        );
        $summaries = array_map(
            fn (array $r): JobSummary => JobSummary::fromJob($this->hydrate($r), PdoSupport::int($r['seq'])),
            $rows,
        );

        return HistoryPage::fromCandidates($summaries, $query->limit);
    }

    public function events(JobId $id): array
    {
        $rows = $this->db->fetchRows('SELECT * FROM printdock_job_events WHERE job_id = ? ORDER BY seq', [$id->value]);

        return array_map(static fn (array $r): JobEvent => new JobEvent(
            JobId::fromString(PdoSupport::string($r['job_id'])),
            AgentId::fromString(PdoSupport::string($r['agent_id'])),
            JobEventType::from(PdoSupport::string($r['event'])),
            JobState::from(PdoSupport::string($r['state_after'])),
            PdoSupport::int($r['attempt']),
            PdoSupport::requiredTime($r['at']),
            null === $r['outcome'] ? null : Outcome::from(PdoSupport::string($r['outcome'])),
            null === $r['reason'] ? null : ReasonCode::fromString(PdoSupport::string($r['reason'])),
            PdoSupport::stringOrNull($r['detail']),
            PdoSupport::stringOrNull($r['spool_job_id']),
            0 !== PdoSupport::int($r['late']),
            PdoSupport::int($r['seq']),
        ), $rows);
    }

    public function sweep(AgentId $agentId, \DateTimeImmutable $now, int $spoolSilenceSeconds): void
    {
        $silentSince = PdoSupport::formatTime($now->modify(sprintf('-%d seconds', $spoolSilenceSeconds)));
        $this->db->transactional(function () use ($agentId, $now, $spoolSilenceSeconds, $silentSince): void {
            $rows = $this->db->fetchRows(
                'SELECT ' . self::SUMMARY_COLUMNS . ' FROM printdock_jobs WHERE agent_id = ?'
                . " AND ((state = 'leased' AND lease_until IS NOT NULL AND lease_until < ?)"
                . " OR (state = 'spooled' AND last_contact_at IS NOT NULL AND last_contact_at < ?))"
                . ' ORDER BY seq' . $this->db->lockClause(),
                [$agentId->value, PdoSupport::formatTime($now), $silentSince],
            );
            foreach ($rows as $row) {
                $job = $this->hydrate($row);
                $job->applyTimeouts($now, $spoolSilenceSeconds);
                $this->update($job);
            }
        });
    }

    public function claimNext(AgentId $agentId, array $printerIds, \DateTimeImmutable $now, int $leaseSeconds): ?Claim
    {
        if ([] === $printerIds) {
            return null;
        }
        $in = implode(', ', array_fill(0, count($printerIds), '?'));

        return $this->db->transactional(function () use ($agentId, $printerIds, $now, $leaseSeconds, $in): ?Claim {
            $row = $this->db->fetchRow(
                "SELECT * FROM printdock_jobs WHERE agent_id = ? AND state = 'leased' AND printer_id IN ($in)"
                . ' ORDER BY seq LIMIT 1' . $this->db->lockClause(),
                [$agentId->value, ...$printerIds],
            );
            if (null !== $row) {
                $job = $this->hydrate($row);
                if ($job->isRedeliverableTo($agentId, $printerIds)) {
                    $job->redeliver($now, $leaseSeconds);
                    $this->update($job);

                    return new Claim($job, true);
                }
            }

            $row = $this->db->fetchRow(
                "SELECT * FROM printdock_jobs WHERE agent_id = ? AND state = 'queued' AND printer_id IN ($in)"
                . ' AND not_before <= ? AND expires_at > ? ORDER BY seq LIMIT 1' . $this->db->lockClause(true),
                [$agentId->value, ...$printerIds, PdoSupport::formatTime($now), PdoSupport::formatTime($now)],
            );
            if (null === $row) {
                return null;
            }
            $job = $this->hydrate($row);
            if (!$job->isClaimableFor($agentId, $printerIds, $now)) {
                return null;
            }
            $job->lease(LeaseToken::generate(), $now, $leaseSeconds);
            $this->update($job);

            return new Claim($job, false);
        });
    }

    public function modify(JobId $id, callable $change): mixed
    {
        return $this->db->transactional(function () use ($id, $change): mixed {
            $row = $this->db->fetchRow('SELECT ' . self::SUMMARY_COLUMNS . ' FROM printdock_jobs WHERE job_id = ?' . $this->db->lockClause(), [$id->value]);
            if (null === $row) {
                return null;
            }
            $job = $this->hydrate($row);
            $result = $change($job);
            $this->update($job);

            return $result;
        });
    }

    public function hasOpenJobs(AgentId $agentId, \DateTimeImmutable $now): bool
    {
        $row = $this->db->fetchRow(
            'SELECT 1 AS present FROM printdock_jobs WHERE agent_id = ?'
            . " AND (state IN ('leased', 'spooled') OR (state = 'queued' AND expires_at > ?)) LIMIT 1",
            [$agentId->value, PdoSupport::formatTime($now)],
        );

        return null !== $row;
    }

    public function purgeContent(\DateTimeImmutable $closedBy, ?AgentId $agentId = null): int
    {
        $time = PdoSupport::formatTime($closedBy);
        $params = ['', $time, $time];
        $sql = 'UPDATE printdock_jobs SET content = ?, content_purged = 1 WHERE content_purged = 0 AND ' . self::CLOSED_BY;
        if (null !== $agentId) {
            $sql .= ' AND agent_id = ?';
            $params[] = $agentId->value;
        }

        return $this->db->run($sql, $params)->rowCount();
    }

    /** How many jobs {@see purgeHistory()} locks and deletes per round (one transaction each). */
    public const PURGE_BATCH = 500;

    /**
     * Each batch of {@see PURGE_BATCH} jobs is its own transaction, so locks are held for one batch at a time and
     * claims and acks interleave between batches. A host must not call this inside its own transaction: the
     * batches would then join it and the locks would last the whole purge.
     */
    public function purgeHistory(\DateTimeImmutable $closedBy): int
    {
        $time = PdoSupport::formatTime($closedBy);
        $deleted = 0;
        do {
            // The ids are locked first and both deletes use that list: a job an ack or sweep closes in between
            // is neither deleted without its events nor leaves events behind.
            $batch = $this->db->transactional(function () use ($time): array {
                $ids = array_map(
                    static fn (array $r): string => PdoSupport::string($r['job_id']),
                    $this->db->fetchRows(
                        'SELECT job_id FROM printdock_jobs WHERE ' . self::CLOSED_BY . ' ORDER BY seq LIMIT ' . self::PURGE_BATCH . $this->db->lockClause(),
                        [$time, $time],
                    ),
                );
                if ([] === $ids) {
                    return [0, 0];
                }
                $in = implode(', ', array_fill(0, count($ids), '?'));
                $this->db->run("DELETE FROM printdock_job_events WHERE job_id IN ($in)", $ids);

                return [count($ids), $this->db->run("DELETE FROM printdock_jobs WHERE job_id IN ($in)", $ids)->rowCount()];
            });
            $deleted += $batch[1];
        } while (self::PURGE_BATCH === $batch[0]);

        return $deleted;
    }

    public function earliestNotBefore(AgentId $agentId, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $row = $this->db->fetchRow(
            "SELECT MIN(not_before) AS earliest FROM printdock_jobs WHERE agent_id = ? AND state = 'queued' AND not_before > ?",
            [$agentId->value, PdoSupport::formatTime($now)],
        );

        return null === $row ? null : PdoSupport::parseTime($row['earliest']);
    }

    /** Writes the job's changing columns (never the content or the snapshots) and appends its events. */
    private function update(Job $job): void
    {
        $row = $this->toRow($job->toData());
        foreach (['job_id', 'content', 'content_size', 'content_purged', 'agent_name', 'printer_name', 'source_ref', 'redeliver_after_lease_expiry'] as $fixed) {
            unset($row[$fixed]);
        }
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($row)));
        $this->db->run("UPDATE printdock_jobs SET $sets WHERE job_id = :job_id", $row + ['job_id' => $job->id()->value]);
        $this->insertEvents($job->pullEvents());
    }

    /**
     * @param list<JobEvent> $events
     */
    private function insertEvents(array $events): void
    {
        foreach ($events as $e) {
            $this->db->run(
                'INSERT INTO printdock_job_events (job_id, agent_id, at, event, state_after, attempt, outcome, reason, detail, spool_job_id, late)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $e->jobId->value,
                    $e->agentId->value,
                    PdoSupport::formatTime($e->at),
                    $e->type->value,
                    $e->stateAfter->value,
                    $e->attempt,
                    $e->outcome?->value,
                    $e->reason?->value,
                    $e->detail,
                    $e->spoolJobId,
                    $e->late,
                ],
            );
        }
    }

    /**
     * @param JobData $d
     *
     * @return array<string, scalar|null>
     */
    private function toRow(array $d): array
    {
        return [
            'job_id' => $d['id'],
            'agent_id' => $d['agent_id'],
            'printer_id' => $d['printer_id'],
            'printer_role' => $d['printer_role'],
            'format' => $d['format'],
            'content' => $d['content'],
            'delivery' => $d['delivery'],
            'options' => null === $d['options'] ? null : PdoSupport::encodeJson($d['options']),
            'title' => $d['title'],
            'title_sensitive' => $d['title_sensitive'],
            'reference' => null === $d['reference'] ? null : PdoSupport::encodeJson($d['reference']),
            'state' => $d['state'],
            'created_at' => PdoSupport::formatTime($d['created_at']),
            'not_before' => PdoSupport::formatTime($d['not_before']),
            'expires_at' => PdoSupport::formatTime($d['expires_at']),
            'attempt' => $d['attempt'],
            'lease_token' => $d['lease_token'],
            'lease_tokens' => PdoSupport::encodeJson($d['lease_tokens']),
            'lease_until' => PdoSupport::formatTime($d['lease_until']),
            'leased_at' => PdoSupport::formatTime($d['leased_at']),
            'last_contact_at' => PdoSupport::formatTime($d['last_contact_at']),
            'finished_at' => PdoSupport::formatTime($d['finished_at']),
            'outcome' => $d['outcome'],
            'reason' => $d['reason'],
            'detail' => $d['detail'],
            'spool_job_id' => $d['spool_job_id'],
            'late' => $d['late'],
            'agent_name' => $d['agent_name'],
            'printer_name' => $d['printer_name'],
            'content_size' => $d['content_size'],
            'content_purged' => $d['content_purged'],
            'redeliver_after_lease_expiry' => $d['redeliver_after_lease_expiry'] ?? true,
            'source_ref' => is_string($d['reference']['source_ref'] ?? null) ? PdoSupport::truncateUtf8($d['reference']['source_ref'], 255) : null,
        ];
    }

    /**
     * @param array<string, mixed> $r a full row, or {@see SUMMARY_COLUMNS} (the content is then read on first use)
     */
    private function hydrate(array $r): Job
    {
        $jobId = PdoSupport::string($r['job_id']);
        $content = array_key_exists('content', $r) ? PdoSupport::string($r['content']) : null;
        $loader = null !== $content ? null : function () use ($jobId): string {
            $row = $this->db->fetchRow('SELECT content FROM printdock_jobs WHERE job_id = ?', [$jobId]);

            return null === $row ? '' : PdoSupport::string($row['content']);
        };

        $options = null === $r['options'] ? null : PdoSupport::decodeJson($r['options']);
        $reference = null === $r['reference'] ? null : PdoSupport::decodeJson($r['reference']);
        $tokens = array_values(array_map(PdoSupport::string(...), PdoSupport::decodeJson($r['lease_tokens'])));

        /** @var array<string, mixed>|null $options */
        /** @var array<string, mixed>|null $reference */
        return Job::fromData([
            'id' => $jobId,
            'agent_id' => PdoSupport::string($r['agent_id']),
            'printer_id' => PdoSupport::string($r['printer_id']),
            'printer_role' => PdoSupport::stringOrNull($r['printer_role']),
            'format' => PdoSupport::string($r['format']),
            'content' => $content,
            'delivery' => PdoSupport::string($r['delivery']),
            'options' => $options,
            'title' => PdoSupport::stringOrNull($r['title']),
            'title_sensitive' => 0 !== PdoSupport::int($r['title_sensitive']),
            'reference' => $reference,
            'state' => PdoSupport::string($r['state']),
            'created_at' => PdoSupport::requiredTime($r['created_at']),
            'not_before' => PdoSupport::requiredTime($r['not_before']),
            'expires_at' => PdoSupport::requiredTime($r['expires_at']),
            'attempt' => PdoSupport::int($r['attempt']),
            'lease_token' => PdoSupport::stringOrNull($r['lease_token']),
            'lease_tokens' => $tokens,
            'lease_until' => PdoSupport::parseTime($r['lease_until']),
            'leased_at' => PdoSupport::parseTime($r['leased_at']),
            'last_contact_at' => PdoSupport::parseTime($r['last_contact_at']),
            'finished_at' => PdoSupport::parseTime($r['finished_at']),
            'outcome' => PdoSupport::stringOrNull($r['outcome']),
            'reason' => PdoSupport::stringOrNull($r['reason']),
            'detail' => PdoSupport::stringOrNull($r['detail']),
            'spool_job_id' => PdoSupport::stringOrNull($r['spool_job_id']),
            'late' => 0 !== PdoSupport::int($r['late']),
            'agent_name' => PdoSupport::stringOrNull($r['agent_name']),
            'printer_name' => PdoSupport::stringOrNull($r['printer_name']),
            'content_size' => PdoSupport::int($r['content_size']),
            'content_purged' => 0 !== PdoSupport::int($r['content_purged']),
            'redeliver_after_lease_expiry' => 0 !== PdoSupport::int($r['redeliver_after_lease_expiry']),
        ], $loader);
    }
}
