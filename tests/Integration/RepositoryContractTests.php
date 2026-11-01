<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Integration;

use PrintDock\Server\Domain\AdminException;
use PrintDock\Server\Domain\AgentActivity;
use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\DiscoveryReport;
use PrintDock\Server\Domain\HistoryQuery;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobFormat;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobState;
use PrintDock\Server\Domain\NewJob;
use PrintDock\Server\Domain\Outcome;
use PrintDock\Server\Domain\ProtocolError;
use PrintDock\Server\Tests\Support\Stack;

/**
 * What the ports promise, tested on the repositories directly. Mixed into the per-adapter test classes next to
 * the protocol rules, so every adapter proves both.
 *
 * @property Stack $stack
 */
trait RepositoryContractTests
{
    public function test_claim_next_leases_the_oldest_queued_job_and_then_redelivers_it_instead_of_leasing_another(): void
    {
        $agent = $this->stack->register();
        $first = $this->stack->enqueue($agent);
        $second = $this->stack->enqueue($agent);
        $now = $this->stack->clock->now();

        $a = $this->stack->jobs->claimNext($agent->agent->id(), ['p1'], $now, 10);
        $b = $this->stack->jobs->claimNext($agent->agent->id(), ['p1'], $now, 10);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame($first->id()->value, $a->job->id()->value);
        self::assertFalse($a->redelivered);
        self::assertSame($first->id()->value, $b->job->id()->value);
        self::assertTrue($b->redelivered);
        self::assertSame($second->id()->value, $this->stack->jobs->find($second->id())?->id()->value);
        self::assertSame(JobState::Queued, $this->stack->jobs->find($second->id())->state());
    }

    public function test_claim_next_only_considers_the_agents_own_jobs_on_the_requested_printers(): void
    {
        $agent = $this->stack->register([], ['p1', 'p2']);
        $other = $this->stack->register();
        $this->stack->enqueue($other, 'p1');
        $onP2 = $this->stack->enqueue($agent, 'p2');
        $now = $this->stack->clock->now();

        self::assertNull($this->stack->jobs->claimNext($agent->agent->id(), ['p1'], $now, 10));
        $claim = $this->stack->jobs->claimNext($agent->agent->id(), ['p1', 'p2'], $now, 10);
        self::assertSame($onP2->id()->value, $claim?->job->id()->value);
    }

    public function test_claim_next_with_no_printers_returns_nothing(): void
    {
        $agent = $this->stack->register();
        $this->stack->enqueue($agent);

        self::assertNull($this->stack->jobs->claimNext($agent->agent->id(), [], $this->stack->clock->now(), 10));
    }

    public function test_claim_next_never_touches_spooled_jobs(): void
    {
        $agent = $this->stack->register();
        $job = $this->stack->enqueue($agent);
        $claim = $this->stack->jobs->claimNext($agent->agent->id(), ['p1'], $this->stack->clock->now(), 10);
        $token = $claim?->job->leaseToken();
        self::assertNotNull($token);
        $this->stack->jobs->modify($job->id(), fn (Job $j) => $j->acknowledge($token, Outcome::Spooled, null, null, null, $this->stack->clock->now()));

        self::assertNull($this->stack->jobs->claimNext($agent->agent->id(), ['p1'], $this->stack->clock->now(), 10));
    }

    public function test_modify_persists_what_the_callable_changed_and_returns_its_result(): void
    {
        $agent = $this->stack->register();
        $job = $this->stack->enqueue($agent);

        $result = $this->stack->jobs->modify($job->id(), fn (Job $j): string => $j->state()->value);
        $this->stack->jobs->modify($job->id(), fn (Job $j) => $j->cancel($this->stack->clock->now()));

        self::assertSame('queued', $result);
        self::assertSame(JobState::Cancelled, $this->stack->jobs->find($job->id())?->state());
    }

    public function test_modify_saves_nothing_when_the_callable_throws(): void
    {
        $agent = $this->stack->register();
        $job = $this->stack->enqueue($agent);

        try {
            $this->stack->jobs->modify($job->id(), function (Job $j): void {
                $j->cancel($this->stack->clock->now());
                throw ProtocolError::conflict('nope', 'queued');
            });
            self::fail('the exception must propagate');
        } catch (ProtocolError) {
        }

        self::assertSame(JobState::Queued, $this->stack->jobs->find($job->id())?->state());
    }

    public function test_modify_returns_null_for_an_unknown_job_without_calling_the_callable(): void
    {
        $called = false;

        $result = $this->stack->jobs->modify(JobId::generate(), function () use (&$called): bool {
            $called = true;

            return true;
        });

        self::assertNull($result);
        self::assertFalse($called);
    }

    public function test_sweep_requeues_lapsed_leases_and_turns_silent_spooled_jobs_into_uncertain(): void
    {
        $agent = $this->stack->register();
        $id = $agent->agent->id();
        $leased = $this->stack->enqueue($agent);
        $spooled = $this->stack->enqueue($agent);
        $untouched = $this->stack->enqueue($agent);
        $now = $this->stack->clock->now();
        $this->stack->jobs->claimNext($id, ['p1'], $now, 10);
        $this->stack->jobs->modify($spooled->id(), function (Job $j) use ($now): void {
            $j->lease(\PrintDock\Server\Domain\LeaseToken::generate(), $now, 10);
            $j->acknowledge($j->leaseToken() ?? throw new \LogicException(), Outcome::Spooled, null, null, null, $now);
        });
        $this->stack->clock->advance(11);

        $this->stack->jobs->sweep($id, $this->stack->clock->now(), 30);
        self::assertSame(JobState::Queued, $this->stack->jobs->find($leased->id())?->state());
        self::assertSame(JobState::Spooled, $this->stack->jobs->find($spooled->id())?->state());
        $this->stack->clock->advance(20);
        $this->stack->jobs->sweep($id, $this->stack->clock->now(), 30);

        self::assertSame(JobState::Uncertain, $this->stack->jobs->find($spooled->id())?->state());
        self::assertSame(JobState::Queued, $this->stack->jobs->find($untouched->id())?->state());
        self::assertNotNull($this->stack->jobs->find($spooled->id())?->finishedAt());
    }

    public function test_sweep_leaves_other_agents_jobs_alone(): void
    {
        $agent = $this->stack->register();
        $other = $this->stack->register();
        $job = $this->stack->enqueue($other);
        $this->stack->jobs->claimNext($other->agent->id(), ['p1'], $this->stack->clock->now(), 10);
        $this->stack->clock->advance(11);

        $this->stack->jobs->sweep($agent->agent->id(), $this->stack->clock->now(), 30);

        self::assertSame(JobState::Leased, $this->stack->jobs->find($job->id())?->state());
    }

    public function test_earliest_not_before_is_the_soonest_future_due_time_among_queued_jobs(): void
    {
        $agent = $this->stack->register();
        $id = $agent->agent->id();
        $now = $this->stack->clock->now();
        self::assertNull($this->stack->jobs->earliestNotBefore($id, $now));
        $this->stack->enqueue($agent, notBefore: 40.0);
        $soon = $this->stack->enqueue($agent, notBefore: 15.0);
        $this->stack->enqueue($agent);

        self::assertEquals($soon->notBefore(), $this->stack->jobs->earliestNotBefore($id, $now));
    }

    public function test_binary_content_and_all_job_fields_survive_storage(): void
    {
        $agent = $this->stack->register();
        $content = "\x00\x01\xff^XA\r\n\u{0105}\u{017c}^XZ" . random_bytes(2048);
        $job = $this->stack->admin->enqueue(new NewJob(
            $agent->agent->id(),
            'p1',
            'label',
            JobFormat::Pdf,
            $content,
            options: ['copies' => 2, 'fit' => 'page', 'rotate' => 90],
            title: 'Zażółć',
            titleSensitive: true,
            reference: ['kind' => 'courier_label', 'nested' => ['a' => 1]],
            notBeforeInSeconds: 1.5,
            expiresInSeconds: 120.25,
        ));

        $loaded = $this->stack->jobs->find($job->id());

        self::assertNotNull($loaded);
        self::assertSame($content, $loaded->content());
        self::assertSame(hash('sha256', $content), $loaded->contentSha256());
        self::assertEquals($job->toData(), $loaded->toData());
    }

    public function test_agents_round_trip_and_are_found_by_key_id(): void
    {
        $registered = $this->stack->register(['lease_s' => 7, 'poll_interval_s' => 3, 'hello_interval_s' => 30, 'spool_timeout_s' => 90]);
        $keyId = substr($registered->key, 4, 8);

        $byKey = $this->stack->agents->findByKeyId($keyId);
        $byId = $this->stack->agents->find($registered->agent->id());

        self::assertNotNull($byKey);
        self::assertNotNull($byId);
        self::assertEquals($registered->agent->toData(), $byKey->toData());
        self::assertEquals($byKey->toData(), $byId->toData());
        self::assertSame(7, $byKey->settings()->leaseS);
        self::assertNull($this->stack->agents->findByKeyId('zzzzzzzz'));
        self::assertNull($this->stack->agents->find(AgentId::generate()));
    }

    public function test_an_administrative_save_does_not_overwrite_what_hello_and_activity_wrote(): void
    {
        $registered = $this->stack->register();
        $id = $registered->agent->id();
        $this->stack->hello($registered, ['agent' => ['hostname' => 'PACK-9']]);
        $this->stack->agents->recordActivity($id, AgentActivity::Claim, $this->stack->clock->now());
        $stale = $registered->agent;
        $stale->rename('Renamed');

        $this->stack->agents->save($stale);
        $stored = $this->stack->agents->find($id);

        self::assertSame('Renamed', $stored?->name());
        self::assertSame('PACK-9', $stored->lastHello()?->hostname);
        self::assertSame(1, $stored->helloCount());
        self::assertSame(1, $stored->claimCount());
    }

    public function test_all_lists_every_agent(): void
    {
        $this->stack->register();
        $this->stack->register();

        self::assertCount(2, $this->stack->agents->all());
    }

    public function test_deleting_an_agent_requires_a_revoked_key(): void
    {
        $agent = $this->stack->register();

        try {
            $this->stack->admin->deleteAgent($agent->agent->id());
            self::fail('expected AdminException');
        } catch (AdminException $e) {
            self::assertSame(AdminException::AGENT_NOT_REVOKED, $e->kind);
        }
        self::assertNotNull($this->stack->agents->find($agent->agent->id()));
    }

    public function test_deleting_an_agent_is_blocked_by_each_kind_of_unfinished_job(): void
    {
        foreach (['queued', 'leased', 'spooled'] as $state) {
            $agent = $this->stack->register();
            $job = $this->stack->enqueue($agent);
            if ('queued' !== $state) {
                $claim = $this->stack->jobs->claimNext($agent->agent->id(), ['p1'], $this->stack->clock->now(), 60);
                $token = $claim?->job->leaseToken();
                self::assertNotNull($token);
                if ('spooled' === $state) {
                    $this->stack->jobs->modify($job->id(), fn (Job $j) => $j->acknowledge($token, Outcome::Spooled, null, null, null, $this->stack->clock->now()));
                }
            }
            $this->stack->admin->updateAgent($agent->agent->id(), static fn ($a) => $a->revoke());

            try {
                $this->stack->admin->deleteAgent($agent->agent->id());
                self::fail('expected AdminException for ' . $state);
            } catch (AdminException $e) {
                self::assertSame(AdminException::AGENT_HAS_OPEN_JOBS, $e->kind, $state);
            }
            self::assertNotNull($this->stack->agents->find($agent->agent->id()), $state);
            self::assertNotNull($this->stack->jobs->find($job->id()), $state);
        }
    }

    public function test_a_queued_job_past_its_deadline_does_not_block_deleting_the_agent(): void
    {
        $agent = $this->stack->register();
        $job = $this->stack->enqueue($agent, expiresIn: 60.0);
        $this->stack->admin->updateAgent($agent->agent->id(), static fn ($a) => $a->revoke());
        $this->stack->clock->advance(61);

        $this->stack->admin->deleteAgent($agent->agent->id());

        self::assertNull($this->stack->agents->find($agent->agent->id()));
        self::assertTrue($this->stack->jobs->find($job->id())?->contentPurged());
    }

    public function test_a_dead_station_whose_jobs_are_past_their_timeouts_can_be_deleted(): void
    {
        $agent = $this->stack->register(['lease_s' => 10, 'spool_timeout_s' => 20]);
        $spooled = $this->stack->enqueue($agent, expiresIn: 60.0);
        $first = $this->stack->claimJob($agent);
        $this->stack->ack($agent, $first->id(), $first->token(), 'spooled');
        $leased = $this->stack->enqueue($agent, expiresIn: 60.0);
        $this->stack->claimJob($agent);
        $this->stack->admin->updateAgent($agent->agent->id(), static fn ($a) => $a->revoke());
        $this->stack->clock->advance(120);

        $this->stack->admin->deleteAgent($agent->agent->id());

        self::assertNull($this->stack->agents->find($agent->agent->id()));
        self::assertSame(JobState::Uncertain, $this->stack->jobs->find($spooled->id())?->state());
        self::assertTrue($this->stack->jobs->find($leased->id())?->contentPurged());
    }

    public function test_deleting_a_revoked_agent_removes_its_discovery_data_and_keeps_its_job_history_without_content(): void
    {
        $agent = $this->stack->register();
        $other = $this->stack->register();
        $id = $agent->agent->id();
        $cancelled = $this->stack->enqueue($agent);
        $this->stack->admin->cancel($cancelled->id());
        $theirs = $this->stack->enqueue($other);
        $this->stack->discovery->open($id, 'dr_1', $this->stack->clock->now());
        $this->stack->discovery->storeResult($id, DiscoveryReport::fromArray(['request_id' => 'dr_1', 'discovered_printers' => []]), $this->stack->clock->now());
        $this->stack->discovery->open($id, 'dr_2', $this->stack->clock->now());
        $this->stack->discovery->open($other->agent->id(), 'dr_x', $this->stack->clock->now());
        $this->stack->admin->updateAgent($id, static fn ($a) => $a->revoke());

        $this->stack->admin->deleteAgent($id);

        self::assertNull($this->stack->agents->find($id));
        self::assertNull($this->stack->agents->findByKeyId($agent->agent->keyId()));
        $kept = $this->stack->jobs->find($cancelled->id());
        self::assertNotNull($kept);
        self::assertTrue($kept->contentPurged());
        self::assertSame('', $kept->content());
        self::assertSame(6, $kept->contentSize());
        self::assertSame('Packing 1', $kept->agentName());
        self::assertSame(['enqueued', 'cancelled'], $this->eventTypes($cancelled->id()));
        self::assertSame([$cancelled->id()->value], $this->historyIds(new HistoryQuery(agentId: $id)));
        self::assertNull($this->stack->discovery->latest($id));
        self::assertNull($this->stack->discovery->openRequestId($id));
        self::assertNotNull($this->stack->agents->find($other->agent->id()));
        self::assertFalse($this->stack->jobs->find($theirs->id())?->contentPurged());
        self::assertSame('dr_x', $this->stack->discovery->openRequestId($other->agent->id()));
    }

    public function test_deleting_an_unknown_agent_is_not_found(): void
    {
        try {
            $this->stack->admin->deleteAgent(AgentId::generate());
            self::fail('expected AdminException');
        } catch (AdminException $e) {
            self::assertSame(AdminException::NOT_FOUND, $e->kind);
        }
    }
}
