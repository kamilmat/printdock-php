<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\ErrorCode;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobEvent;
use PrintDock\Server\Domain\JobFormat;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobState;
use PrintDock\Server\Domain\LeaseToken;
use PrintDock\Server\Domain\NewJob;
use PrintDock\Server\Domain\Outcome;
use PrintDock\Server\Domain\ProtocolError;
use PrintDock\Server\Domain\ReasonCode;
use PrintDock\Server\Domain\Time;

/**
 * The per-job rules of sections 5, 6 and 10, without any transport or storage.
 */
final class JobRulesTest extends TestCase
{
    private const LEASE = 10;
    private const SILENCE = 30;

    private \DateTimeImmutable $now;
    private AgentId $agent;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-30T13:41:30Z');
        $this->agent = AgentId::generate();
    }

    private function job(float $expiresIn = 900.0, float $notBefore = 0.0, ?AgentId $agent = null): Job
    {
        return Job::enqueue(
            JobId::generate(),
            new NewJob($agent ?? $this->agent, 'p1', 'label', JobFormat::Zpl, '^XA^XZ', notBeforeInSeconds: $notBefore, expiresInSeconds: $expiresIn),
            $this->now,
        );
    }

    private function leased(): Job
    {
        $job = $this->job();
        $job->lease(LeaseToken::generate(), $this->now, self::LEASE);

        return $job;
    }

    private function later(float $seconds): \DateTimeImmutable
    {
        return Time::add($this->now, $seconds);
    }

    private function ack(Job $job, Outcome $outcome, ?\DateTimeImmutable $at = null, ?LeaseToken $token = null): \PrintDock\Server\Domain\AckResult
    {
        $reason = Outcome::Rejected === $outcome ? ReasonCode::fromString('expired') : null;

        return $job->acknowledge($token ?? $job->leaseToken() ?? LeaseToken::generate(), $outcome, $reason, null, null, $at ?? $this->now);
    }

    private function jobIn(JobState $state): Job
    {
        $job = $this->leased();
        match ($state) {
            JobState::Queued => $job->applyTimeouts($this->later(11), self::SILENCE),
            JobState::Leased => null,
            JobState::Spooled => $this->ack($job, Outcome::Spooled),
            JobState::Printed => $this->ack($job, Outcome::Printed),
            JobState::Failed => $this->ack($job, Outcome::Failed),
            JobState::Uncertain => $this->ack($job, Outcome::Uncertain),
            JobState::Cancelled => $job->cancel($this->now),
        };

        return $job;
    }

    private function assertProtocolError(ErrorCode $code, callable $action): ProtocolError
    {
        try {
            $action();
        } catch (ProtocolError $e) {
            self::assertSame($code, $e->errorCode);

            return $e;
        }
        self::fail('expected ' . $code->value);
    }

    // -- claim rules (5.4) ----------------------------------------------------------

    public function test_a_new_lease_increments_attempt_and_issues_a_fresh_token(): void
    {
        $job = $this->job();
        $first = LeaseToken::generate();
        $job->lease($first, $this->now, self::LEASE);
        $job->applyTimeouts($this->later(11), self::SILENCE);
        $second = LeaseToken::generate();
        $job->lease($second, $this->later(11), self::LEASE);

        self::assertSame(2, $job->attempt());
        self::assertTrue($job->leaseToken()?->equals($second));
        self::assertTrue($job->hasIssued($first));
        self::assertTrue($job->hasIssued($second));
    }

    public function test_only_the_agents_own_leased_job_on_a_requested_printer_is_redeliverable(): void
    {
        $job = $this->leased();

        self::assertTrue($job->isRedeliverableTo($this->agent, ['p1']));
        self::assertFalse($job->isRedeliverableTo(AgentId::generate(), ['p1']));
        self::assertFalse($job->isRedeliverableTo($this->agent, ['p2']));
    }

    public function test_spooled_jobs_are_never_redeliverable(): void
    {
        self::assertFalse($this->jobIn(JobState::Spooled)->isRedeliverableTo($this->agent, ['p1']));
    }

    public function test_redelivery_refreshes_the_lease_and_keeps_token_and_attempt(): void
    {
        $job = $this->leased();
        $token = $job->leaseToken();

        $job->redeliver($this->later(4), self::LEASE);

        self::assertSame($token, $job->leaseToken());
        self::assertSame(1, $job->attempt());
        self::assertEquals($this->later(14), $job->leaseUntil());
    }

    public function test_a_queued_job_is_claimable_only_when_due_unexpired_and_on_a_requested_printer(): void
    {
        $job = $this->job(expiresIn: 60, notBefore: 10);

        self::assertFalse($job->isClaimableFor($this->agent, ['p1'], $this->now), 'not due yet');
        self::assertTrue($job->isClaimableFor($this->agent, ['p1'], $this->later(10)));
        self::assertFalse($job->isClaimableFor($this->agent, ['p2'], $this->later(10)), 'other printer');
        self::assertFalse($job->isClaimableFor(AgentId::generate(), ['p1'], $this->later(10)), 'other agent');
        self::assertFalse($job->isClaimableFor($this->agent, ['p1'], $this->later(61)), 'expired');
    }

    public function test_a_leased_job_is_not_claimable_as_new_work(): void
    {
        self::assertFalse($this->leased()->isClaimableFor($this->agent, ['p1'], $this->now));
    }

    // -- sweep (5.4.1, 10) ------------------------------------------------------------

    public function test_lease_expiry_returns_a_leased_job_to_queued_and_keeps_the_attempt(): void
    {
        $job = $this->leased();

        $job->applyTimeouts($this->later(10), self::SILENCE);
        self::assertSame(JobState::Leased, $job->state(), 'the lease is still valid at lease_until');
        $job->applyTimeouts($this->later(10.5), self::SILENCE);

        self::assertSame(JobState::Queued, $job->state());
        self::assertSame(1, $job->attempt());
        self::assertNull($job->leaseUntil());
    }

    public function test_lease_expiry_never_requeues_a_spooled_job(): void
    {
        $job = $this->jobIn(JobState::Spooled);

        $job->applyTimeouts($this->later(self::LEASE + 1), self::SILENCE);

        self::assertSame(JobState::Spooled, $job->state());
    }

    public function test_a_spooled_job_silent_for_longer_than_the_silence_limit_becomes_uncertain_not_queued(): void
    {
        $job = $this->jobIn(JobState::Spooled);

        $job->applyTimeouts($this->later(self::SILENCE), self::SILENCE);
        self::assertSame(JobState::Spooled, $job->state());
        $job->applyTimeouts($this->later(self::SILENCE + 1), self::SILENCE);

        self::assertSame(JobState::Uncertain, $job->state());
        self::assertNotNull($job->finishedAt());
    }

    public function test_contact_resets_the_spool_silence_clock(): void
    {
        $job = $this->jobIn(JobState::Spooled);
        $this->ack($job, Outcome::Spooled, $this->later(25));

        $job->applyTimeouts($this->later(50), self::SILENCE);

        self::assertSame(JobState::Spooled, $job->state());
    }

    public function test_final_states_are_untouched_by_the_sweep(): void
    {
        foreach ([JobState::Printed, JobState::Failed, JobState::Uncertain, JobState::Cancelled] as $state) {
            $job = $this->jobIn($state);
            $job->applyTimeouts($this->later(1000), self::SILENCE);
            self::assertSame($state, $job->state());
        }
    }

    public function test_expired_is_computed_for_queued_and_leased_jobs_and_never_stored(): void
    {
        $queued = $this->job(expiresIn: 5);
        $leased = $this->leased();
        $printed = $this->jobIn(JobState::Printed);

        self::assertSame('queued', $queued->reportedStatus($this->later(5)));
        self::assertSame('expired', $queued->reportedStatus($this->later(6)));
        self::assertSame('expired', $leased->reportedStatus($this->later(901)));
        self::assertSame('printed', $printed->reportedStatus($this->later(901)));
        self::assertSame(JobState::Queued, $queued->state());
    }

    // -- ack rules (6.4) --------------------------------------------------------------

    public function test_an_ack_with_a_token_never_issued_is_bad_lease_token(): void
    {
        $job = $this->leased();

        $this->assertProtocolError(ErrorCode::BadLeaseToken, fn () => $this->ack($job, Outcome::Printed, token: LeaseToken::generate()));
        self::assertSame(JobState::Leased, $job->state());
    }

    public function test_an_ack_with_the_token_of_an_earlier_lease_is_accepted_and_late(): void
    {
        $job = $this->leased();
        $first = $job->leaseToken();
        $job->applyTimeouts($this->later(11), self::SILENCE);
        $job->lease(LeaseToken::generate(), $this->later(11), self::LEASE);

        $result = $this->ack($job, Outcome::Printed, $this->later(12), $first);

        self::assertSame(JobState::Printed, $result->state);
        self::assertTrue($result->late);
    }

    public function test_an_ack_within_the_lease_and_before_expiry_is_not_late(): void
    {
        self::assertFalse($this->ack($this->leased(), Outcome::Printed, $this->later(5))->late);
    }

    public function test_the_final_ack_of_a_spooled_job_after_its_lease_is_not_late(): void
    {
        $job = $this->leased();
        $this->ack($job, Outcome::Spooled, $this->later(1));
        $result = $this->ack($job, Outcome::Printed, $this->later(120));
        self::assertSame(JobState::Printed, $result->state);
        self::assertFalse($result->late);
    }

    public function test_an_ack_after_expires_at_is_recorded_and_late(): void
    {
        $job = $this->job(expiresIn: 5);
        $job->lease(LeaseToken::generate(), $this->now, 600);

        $result = $this->ack($job, Outcome::Printed, $this->later(6));

        self::assertSame(JobState::Printed, $result->state);
        self::assertTrue($result->late);
    }

    public function test_an_ack_on_a_job_that_went_back_to_queued_is_applied_and_late(): void
    {
        $job = $this->leased();
        $job->applyTimeouts($this->later(11), self::SILENCE);

        $result = $this->ack($job, Outcome::Printed, $this->later(11));

        self::assertSame(JobState::Printed, $result->state);
        self::assertTrue($result->late);
    }

    /** @return iterable<string, array{JobState, Outcome, ?JobState, ?string, bool}> */
    public static function ackMatrix(): iterable
    {
        // from state, outcome => resulting state (null = 409), status in the 409 body, late
        foreach ([JobState::Queued, JobState::Leased, JobState::Spooled] as $open) {
            foreach (Outcome::cases() as $outcome) {
                yield sprintf('%s + %s applies', $open->value, $outcome->value) => [$open, $outcome, $outcome->resultingState(), null, JobState::Queued === $open];
            }
        }
        foreach ([
            'printed' => ['printed' => 'printed', 'failed' => null, 'rejected' => null, 'uncertain' => null, 'spooled' => null],
            'failed' => ['failed' => 'failed', 'rejected' => 'failed', 'printed' => null, 'uncertain' => null, 'spooled' => null],
        ] as $from => $row) {
            foreach ($row as $outcome => $to) {
                yield sprintf('%s + %s %s', $from, $outcome, null === $to ? 'conflicts' : 'is an idempotent repeat') => [
                    JobState::from($from), Outcome::from($outcome), null === $to ? null : JobState::from($to), $from, false,
                ];
            }
        }
        foreach (['printed' => JobState::Printed, 'failed' => JobState::Failed, 'rejected' => JobState::Failed] as $outcome => $to) {
            yield 'uncertain + ' . $outcome . ' is corrected by the agent' => [JobState::Uncertain, Outcome::from($outcome), $to, null, true];
        }
        yield 'uncertain + uncertain is an idempotent repeat' => [JobState::Uncertain, Outcome::Uncertain, JobState::Uncertain, null, false];
        yield 'uncertain + spooled conflicts' => [JobState::Uncertain, Outcome::Spooled, null, 'uncertain', false];
        foreach (Outcome::cases() as $outcome) {
            yield 'cancelled + ' . $outcome->value . ' conflicts' => [JobState::Cancelled, $outcome, null, 'cancelled', false];
        }
    }

    #[DataProvider('ackMatrix')]
    public function test_ack_matrix_of_section_6_4(JobState $from, Outcome $outcome, ?JobState $expected, ?string $conflictStatus, bool $late): void
    {
        $job = $this->jobIn($from);

        if (null === $expected) {
            $error = $this->assertProtocolError(ErrorCode::Conflict, fn () => $this->ack($job, $outcome, $this->later(1)));
            self::assertSame($conflictStatus, $error->extra['status']);
            self::assertSame($from, $job->state(), 'a conflict changes nothing');

            return;
        }

        $result = $this->ack($job, $outcome, $this->later(1));

        self::assertSame($expected, $result->state);
        self::assertSame($late, $result->late);
        self::assertSame($expected, $job->state());
    }

    public function test_a_repeated_ack_changes_nothing(): void
    {
        $job = $this->leased();
        $this->ack($job, Outcome::Failed, $this->later(1));
        $finished = $job->finishedAt();

        $this->ack($job, Outcome::Rejected, $this->later(5));

        self::assertEquals($finished, $job->finishedAt());
        self::assertSame(Outcome::Failed, $job->outcome());
    }

    public function test_an_ack_records_outcome_reason_detail_and_spool_id(): void
    {
        $job = $this->leased();

        $job->acknowledge($job->leaseToken() ?? LeaseToken::generate(), Outcome::Failed, ReasonCode::fromString('printer_unreachable'), 'Connection refused', 'sp-7', $this->later(1));

        self::assertSame('printer_unreachable', $job->reason()?->value);
        self::assertSame('Connection refused', $job->detail());
        self::assertSame('sp-7', $job->spoolJobId());
        self::assertEquals($this->later(1), $job->finishedAt());
    }

    public function test_cancelling_a_final_job_conflicts_and_cancelling_an_open_one_closes_it(): void
    {
        $this->assertProtocolError(ErrorCode::Conflict, fn () => $this->jobIn(JobState::Printed)->cancel($this->now));
        $job = $this->job();

        $job->cancel($this->now);

        self::assertSame(JobState::Cancelled, $job->state());
        self::assertFalse($job->isClaimableFor($this->agent, ['p1'], $this->now));
    }

    // -- extend (5.6) -----------------------------------------------------------------

    public function test_extend_pushes_the_lease_without_changing_state_or_attempt(): void
    {
        $job = $this->leased();

        $until = $job->extend($job->leaseToken() ?? LeaseToken::generate(), $this->later(6), self::LEASE);

        self::assertEquals($this->later(16), $until);
        self::assertSame(JobState::Leased, $job->state());
        self::assertSame(1, $job->attempt());
    }

    public function test_extend_with_a_token_never_issued_is_bad_lease_token(): void
    {
        $job = $this->leased();

        $this->assertProtocolError(ErrorCode::BadLeaseToken, fn () => $job->extend(LeaseToken::generate(), $this->now, self::LEASE));
    }

    public function test_extend_conflicts_unless_the_job_is_leased_with_this_very_token(): void
    {
        $spooled = $this->jobIn(JobState::Spooled);
        $closed = $this->jobIn(JobState::Printed);
        $superseded = $this->leased();
        $old = $superseded->leaseToken() ?? LeaseToken::generate();
        $superseded->applyTimeouts($this->later(11), self::SILENCE);
        $superseded->lease(LeaseToken::generate(), $this->later(11), self::LEASE);

        $a = $this->assertProtocolError(ErrorCode::Conflict, fn () => $spooled->extend($spooled->leaseToken() ?? LeaseToken::generate(), $this->now, self::LEASE));
        $b = $this->assertProtocolError(ErrorCode::Conflict, fn () => $closed->extend($closed->leaseToken() ?? LeaseToken::generate(), $this->now, self::LEASE));
        $this->assertProtocolError(ErrorCode::Conflict, fn () => $superseded->extend($old, $this->later(12), self::LEASE));

        self::assertSame('spooled', $a->extra['status']);
        self::assertSame('printed', $b->extra['status']);
    }

    // -- persistence shape ------------------------------------------------------------

    public function test_a_job_survives_a_data_round_trip(): void
    {
        $job = $this->leased();
        $this->ack($job, Outcome::Spooled, $this->later(1));

        $copy = Job::fromData($job->toData());

        self::assertEquals($job->toData(), $copy->toData());
    }

    // -- history ----------------------------------------------------------------------

    /** @return list<string> */
    private function pulled(Job $job): array
    {
        return array_map(static fn (JobEvent $e): string => $e->type->value . ':' . $e->stateAfter->value, $job->pullEvents());
    }

    public function test_each_transition_records_one_event_and_pulling_empties_the_list(): void
    {
        $job = $this->job();
        self::assertSame(['enqueued:queued'], $this->pulled($job));
        self::assertSame([], $job->pullEvents());

        $job->lease(LeaseToken::generate(), $this->now, self::LEASE);
        $job->redeliver($this->later(1), self::LEASE);
        $job->applyTimeouts($this->later(5), self::SILENCE);
        $job->applyTimeouts($this->later(12), self::SILENCE);
        $job->lease(LeaseToken::generate(), $this->later(12), self::LEASE);
        $this->ack($job, Outcome::Spooled, $this->later(13));
        $this->ack($job, Outcome::Spooled, $this->later(14));
        $job->applyTimeouts($this->later(14 + self::SILENCE + 1), self::SILENCE);
        $this->ack($job, Outcome::Uncertain, $this->later(60));

        self::assertSame(
            ['leased:leased', 'redelivered:leased', 'lease_expired:queued', 'leased:leased', 'acked:spooled', 'spool_silence:uncertain'],
            $this->pulled($job),
            'a repeated identical ack, a sweep with nothing to do and a repeated final ack record nothing',
        );
    }

    public function test_an_ack_event_carries_what_the_agent_reported_and_a_cancel_is_recorded(): void
    {
        $job = $this->leased();
        $job->pullEvents();
        $job->acknowledge($job->leaseToken() ?? LeaseToken::generate(), Outcome::Rejected, ReasonCode::fromString('bad_content'), 'sha mismatch', 'sp1', $this->later(20));
        $cancelled = $this->job();
        $cancelled->pullEvents();
        $cancelled->cancel($this->now);

        $event = $job->pullEvents()[0];

        self::assertSame(Outcome::Rejected, $event->outcome);
        self::assertSame('bad_content', $event->reason?->value);
        self::assertSame('sha mismatch', $event->detail);
        self::assertSame('sp1', $event->spoolJobId);
        self::assertTrue($event->late);
        self::assertEquals($this->later(20), $event->at);
        self::assertNull($event->seq);
        self::assertSame(['cancelled:cancelled'], $this->pulled($cancelled));
    }

    public function test_a_job_is_closed_once_final_or_queued_past_its_deadline(): void
    {
        $leased = $this->leased();
        $printed = $this->jobIn(JobState::Printed);
        $queued = $this->job(expiresIn: 60.0);

        self::assertFalse($leased->isClosedBy($this->later(3600)));
        self::assertTrue($printed->isClosedBy($this->now));
        self::assertFalse($printed->isClosedBy($this->later(-1)));
        self::assertFalse($queued->isClosedBy($this->later(59)));
        self::assertTrue($queued->isClosedBy($this->later(60)));
    }

    public function test_purging_content_keeps_its_size(): void
    {
        $job = $this->jobIn(JobState::Printed);

        $job->purgeContent();

        self::assertSame('', $job->content());
        self::assertTrue($job->contentPurged());
        self::assertSame(6, $job->contentSize());
    }
}
