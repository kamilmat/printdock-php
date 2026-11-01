<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Integration;

use PrintDock\Server\Application\RegisteredAgent;
use PrintDock\Server\Domain\AgentSettings;
use PrintDock\Server\Domain\HistoryQuery;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobEvent;
use PrintDock\Server\Domain\JobFormat;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobState;
use PrintDock\Server\Domain\JobSummary;
use PrintDock\Server\Domain\NewJob;
use PrintDock\Server\Domain\Outcome;
use PrintDock\Server\Domain\Printer;
use PrintDock\Server\Domain\PrinterTransport;
use PrintDock\Server\Domain\ProtocolError;
use PrintDock\Server\Tests\Support\Stack;

/**
 * The durable history: events written with every state change, the filtered and paged listing, retention.
 * Mixed into the per-adapter test classes like {@see RepositoryContractTests}.
 *
 * @property Stack $stack
 */
trait HistoryContractTests
{
    /** @return list<string> */
    private function eventTypes(JobId $id): array
    {
        return array_map(static fn (JobEvent $e): string => $e->type->value, $this->stack->admin->jobEvents($id));
    }

    /** @return list<string> */
    private function historyIds(HistoryQuery $query): array
    {
        return array_map(static fn (JobSummary $j): string => $j->id->value, $this->stack->admin->history($query)->jobs);
    }

    private function namedPrinterAgent(): RegisteredAgent
    {
        return $this->stack->admin->registerAgent('Packing 2', AgentSettings::fromArray(['lease_s' => 10, 'spool_timeout_s' => 20]), [
            new Printer('p1', 'label', PrinterTransport::Socket, 'zpl', 203, '100x150mm', '10.0.0.15:9100', name: 'Zebra hall'),
        ]);
    }

    public function test_every_state_change_is_recorded_with_the_state_it_left_the_job_in(): void
    {
        $agent = $this->stack->register(['lease_s' => 10, 'spool_timeout_s' => 20]);
        $job = $this->stack->enqueue($agent);
        $first = $this->stack->claimJob($agent);
        $this->stack->claimJob($agent);
        $this->stack->clock->advance(11);
        $second = $this->stack->claimJob($agent);
        $this->stack->ack($agent, $second->id(), $second->token(), 'spooled');
        $this->stack->ack($agent, $second->id(), $second->token(), 'spooled');
        $this->stack->ack($agent, $second->id(), $second->token(), 'spooled', ['spool_job_id' => '42']);
        $this->stack->clock->advance(61);
        $this->stack->claim($agent);
        $this->stack->ack($agent, $second->id(), $first->token(), 'printed');

        $events = $this->stack->admin->jobEvents($job->id());

        self::assertSame(
            ['enqueued', 'leased', 'redelivered', 'lease_expired', 'leased', 'acked', 'acked', 'spool_silence', 'acked'],
            array_map(static fn (JobEvent $e): string => $e->type->value, $events),
        );
        self::assertSame(
            ['queued', 'leased', 'leased', 'queued', 'leased', 'spooled', 'spooled', 'uncertain', 'printed'],
            array_map(static fn (JobEvent $e): string => $e->stateAfter->value, $events),
        );
        self::assertSame([0, 1, 1, 1, 2, 2, 2, 2, 2], array_map(static fn (JobEvent $e): int => $e->attempt, $events));
        self::assertSame('42', $events[6]->spoolJobId);
        self::assertSame(Outcome::Printed, $events[8]->outcome);
        self::assertTrue($events[8]->late, 'a printed ack after the server guessed uncertain is late');
        $seqs = array_map(static fn (JobEvent $e): ?int => $e->seq, $events);
        $sorted = $seqs;
        sort($sorted);
        self::assertSame($sorted, $seqs);
        self::assertNotContains(null, $seqs);
        self::assertTrue($events[0]->agentId->equals($agent->agent->id()));
    }

    public function test_cancellation_and_a_rejected_ack_are_recorded(): void
    {
        $agent = $this->stack->register();
        $cancelled = $this->stack->enqueue($agent);
        $this->stack->admin->cancel($cancelled->id());
        $this->stack->enqueue($agent);
        $claimed = $this->stack->claimJob($agent);

        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'rejected', ['reason' => 'unsupported_route', 'detail' => 'pdf on epl']);
        $events = $this->stack->admin->jobEvents(JobId::fromString($claimed->id()));

        self::assertSame(['enqueued', 'cancelled'], $this->eventTypes($cancelled->id()));
        self::assertSame(Outcome::Rejected, $events[2]->outcome);
        self::assertSame('unsupported_route', $events[2]->reason?->value);
        self::assertSame('pdf on epl', $events[2]->detail);
        self::assertSame(JobState::Failed, $events[2]->stateAfter);
    }

    public function test_an_ack_that_is_refused_records_nothing(): void
    {
        $agent = $this->stack->register();
        $this->stack->enqueue($agent);
        $claimed = $this->stack->claimJob($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');
        $id = JobId::fromString($claimed->id());

        $conflict = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'failed', ['reason' => 'printer_error']);
        $repeat = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        self::assertSame(409, $conflict->status);
        self::assertSame(200, $repeat->status);
        self::assertSame(['enqueued', 'leased', 'acked'], $this->eventTypes($id));
    }

    public function test_events_recorded_inside_a_change_that_throws_are_rolled_back_with_it(): void
    {
        $agent = $this->stack->register();
        $job = $this->stack->enqueue($agent);

        try {
            $this->stack->jobs->modify($job->id(), function (Job $j): void {
                $j->cancel($this->stack->clock->now());
                throw ProtocolError::conflict('nope', 'queued');
            });
        } catch (ProtocolError) {
        }

        self::assertSame(['enqueued'], $this->eventTypes($job->id()));
    }

    public function test_events_of_an_unknown_job_are_empty(): void
    {
        self::assertSame([], $this->stack->admin->jobEvents(JobId::generate()));
    }

    public function test_jobs_keep_the_station_and_printer_names_they_were_created_with(): void
    {
        $agent = $this->namedPrinterAgent();
        $job = $this->stack->enqueue($agent);
        $this->stack->admin->updateAgent($agent->agent->id(), function ($a): void {
            $a->rename('Renamed');
            $a->replacePrinters([], $this->stack->clock->now());
        });

        $stored = $this->stack->jobs->find($job->id());
        $listed = $this->stack->admin->history()->jobs[0];

        self::assertSame('Packing 2', $stored?->agentName());
        self::assertSame('Zebra hall', $stored->printerName());
        self::assertSame('Packing 2', $listed->agentName);
        self::assertSame('Zebra hall', $listed->printerName);
        self::assertSame(6, $listed->contentSize);
        self::assertNull($this->stack->enqueue($this->stack->register())->printerName(), 'a printer without a name has none');
    }

    public function test_history_lists_newest_first_and_pages_by_cursor(): void
    {
        $agent = $this->stack->register();
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->stack->enqueue($agent)->id()->value;
        }

        $first = $this->stack->admin->history(new HistoryQuery(limit: 2));
        $second = $this->stack->admin->history(new HistoryQuery(before: $first->nextBefore, limit: 2));
        $third = $this->stack->admin->history(new HistoryQuery(before: $second->nextBefore, limit: 2));

        self::assertSame([$ids[4], $ids[3]], array_map(static fn (JobSummary $j): string => $j->id->value, $first->jobs));
        self::assertSame([$ids[2], $ids[1]], array_map(static fn (JobSummary $j): string => $j->id->value, $second->jobs));
        self::assertSame([$ids[0]], array_map(static fn (JobSummary $j): string => $j->id->value, $third->jobs));
        self::assertNotNull($first->nextBefore);
        self::assertNull($third->nextBefore);
        self::assertNull($this->stack->admin->history(new HistoryQuery(limit: 5))->nextBefore, 'an exactly full last page has no cursor');
        self::assertSame(200, (new HistoryQuery(limit: 1000))->limit);
        self::assertSame(1, (new HistoryQuery(limit: 0))->limit);
    }

    public function test_history_filters_by_agent_printer_state_and_creation_time(): void
    {
        $agent = $this->stack->register([], ['p1', 'p2']);
        $other = $this->stack->register();
        $early = $this->stack->enqueue($agent);
        $this->stack->clock->advance(60);
        $from = $this->stack->clock->now();
        $onP2 = $this->stack->enqueue($agent, 'p2');
        $theirs = $this->stack->enqueue($other);
        $this->stack->admin->cancel($theirs->id());
        $this->stack->clock->advance(60);
        $late = $this->stack->enqueue($agent);
        $to = $this->stack->clock->now();
        $agentId = $agent->agent->id();

        self::assertSame([$late->id()->value, $onP2->id()->value, $early->id()->value], $this->historyIds(new HistoryQuery(agentId: $agentId)));
        self::assertSame([$onP2->id()->value], $this->historyIds(new HistoryQuery(agentId: $agentId, printerId: 'p2')));
        self::assertSame([$theirs->id()->value], $this->historyIds(new HistoryQuery(states: [JobState::Cancelled])));
        self::assertCount(4, $this->historyIds(new HistoryQuery(states: [JobState::Queued, JobState::Cancelled])));
        self::assertSame([$theirs->id()->value, $onP2->id()->value], $this->historyIds(new HistoryQuery(createdFrom: $from, createdTo: $to)));
    }

    public function test_history_text_matches_title_job_id_and_source_ref(): void
    {
        $agent = $this->stack->register();
        $dpd = $this->stack->admin->enqueue(new NewJob($agent->agent->id(), 'p1', null, JobFormat::Zpl, '^XA^XZ', title: 'DPD 1234567890'));
        $order = $this->stack->admin->enqueue(new NewJob($agent->agent->id(), 'p1', null, JobFormat::Zpl, '^XA^XZ', title: 'InPost', reference: ['source_ref' => 'order-98765']));
        $wild = $this->stack->admin->enqueue(new NewJob($agent->agent->id(), 'p1', null, JobFormat::Zpl, '^XA^XZ', title: '100% done_x'));

        self::assertSame([$dpd->id()->value], $this->historyIds(new HistoryQuery(text: '4567')));
        self::assertSame([$dpd->id()->value], $this->historyIds(new HistoryQuery(text: 'dpd')), 'ASCII case is ignored');
        self::assertSame([$order->id()->value], $this->historyIds(new HistoryQuery(text: 'order-98765')));
        self::assertSame([$order->id()->value], $this->historyIds(new HistoryQuery(text: substr($order->id()->value, 3))));
        self::assertSame([$wild->id()->value], $this->historyIds(new HistoryQuery(text: '0% d')));
        self::assertSame([], $this->historyIds(new HistoryQuery(text: 'do_e')), 'an underscore is not a wildcard');
        self::assertCount(3, $this->historyIds(new HistoryQuery(text: '  ')));
    }

    public function test_purging_content_keeps_jobs_and_events_of_closed_jobs_only(): void
    {
        $agent = $this->stack->register();
        $printed = $this->stack->enqueue($agent);
        $claimed = $this->stack->claimJob($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');
        $expired = $this->stack->enqueue($agent, expiresIn: 30.0);
        $open = $this->stack->enqueue($agent, expiresIn: 3600.0);
        $this->stack->clock->advance(60);
        $cutoff = $this->stack->clock->now();
        $this->stack->clock->advance(60);
        $recent = $this->stack->enqueue($agent);
        $this->stack->admin->cancel($recent->id());

        $purged = $this->stack->admin->purgeContent($cutoff);
        $again = $this->stack->admin->purgeContent($cutoff);
        $future = $this->stack->admin->purgeContent($this->stack->clock->now()->modify('+1 day'));

        self::assertSame(2, $purged);
        self::assertSame(0, $again);
        self::assertSame(1, $future, 'a cut-off in the future counts as now: the open job keeps its content');
        self::assertTrue($this->stack->jobs->find($printed->id())?->contentPurged());
        self::assertTrue($this->stack->jobs->find($expired->id())?->contentPurged());
        self::assertSame('^XA^XZ', $this->stack->jobs->find($open->id())?->content());
        self::assertTrue($this->stack->jobs->find($recent->id())?->contentPurged());
        self::assertSame(['enqueued', 'leased', 'acked'], $this->eventTypes($printed->id()));
        $listed = $this->stack->admin->history(new HistoryQuery(states: [JobState::Printed]))->jobs[0];
        self::assertTrue($listed->contentPurged);
        self::assertSame(6, $listed->contentSize);
    }

    public function test_purging_history_deletes_closed_jobs_with_their_events(): void
    {
        $agent = $this->stack->register();
        $old = $this->stack->enqueue($agent);
        $this->stack->admin->cancel($old->id());
        $open = $this->stack->enqueue($agent, expiresIn: 3600.0);
        $this->stack->clock->advance(60);
        $cutoff = $this->stack->clock->now();
        $this->stack->clock->advance(1);
        $recent = $this->stack->enqueue($agent);
        $this->stack->admin->cancel($recent->id());

        self::assertSame(1, $this->stack->admin->purgeHistory($cutoff));

        self::assertNull($this->stack->jobs->find($old->id()));
        self::assertSame([], $this->stack->admin->jobEvents($old->id()));
        self::assertNotNull($this->stack->jobs->find($open->id()));
        self::assertSame(['enqueued', 'cancelled'], $this->eventTypes($recent->id()));
        self::assertSame([$recent->id()->value, $open->id()->value], $this->historyIds(new HistoryQuery()));
    }

    public function test_history_text_with_non_ascii_characters_matches_titles_and_source_refs(): void
    {
        $agent = $this->stack->register();
        $bed = $this->stack->admin->enqueue(new NewJob($agent->agent->id(), 'p1', null, JobFormat::Zpl, '^XA^XZ', title: 'Łóżko 160 Hilding'));
        $ref = $this->stack->admin->enqueue(new NewJob($agent->agent->id(), 'p1', null, JobFormat::Zpl, '^XA^XZ', title: 'DPD', reference: ['source_ref' => 'zamówienie-12']));
        $this->stack->admin->enqueue(new NewJob($agent->agent->id(), 'p1', null, JobFormat::Zpl, '^XA^XZ', title: 'Materac'));

        self::assertSame([$bed->id()->value], $this->historyIds(new HistoryQuery(text: 'Łóżko')));
        self::assertSame([$ref->id()->value], $this->historyIds(new HistoryQuery(text: 'zamówienie')));
        self::assertSame([], $this->historyIds(new HistoryQuery(text: 'żółw')));
    }

    public function test_history_text_never_matches_the_title_of_a_title_sensitive_job(): void
    {
        $agent = $this->stack->register();
        $hidden = $this->stack->admin->enqueue(new NewJob($agent->agent->id(), 'p1', null, JobFormat::Zpl, '^XA^XZ', title: 'Jan Kowalski, Długa 5', titleSensitive: true, reference: ['source_ref' => 'order-55']));
        $open = $this->stack->admin->enqueue(new NewJob($agent->agent->id(), 'p1', null, JobFormat::Zpl, '^XA^XZ', title: 'Kowalski'));

        self::assertSame([$open->id()->value], $this->historyIds(new HistoryQuery(text: 'Kowalski')));
        self::assertSame([], $this->historyIds(new HistoryQuery(text: 'Długa')));
        self::assertSame([$hidden->id()->value], $this->historyIds(new HistoryQuery(text: 'order-55')));
        self::assertSame([$hidden->id()->value], $this->historyIds(new HistoryQuery(text: substr($hidden->id()->value, 3))));
    }

    public function test_find_summary_reads_one_job_without_content(): void
    {
        $agent = $this->namedPrinterAgent();
        $job = $this->stack->enqueue($agent);
        $this->stack->enqueue($agent);

        $summary = $this->stack->admin->findSummary($job->id());

        self::assertNotNull($summary);
        self::assertSame($job->id()->value, $summary->id->value);
        self::assertSame($this->stack->admin->history()->jobs[1]->seq, $summary->seq);
        self::assertSame('Zebra hall', $summary->printerName);
        self::assertSame(6, $summary->contentSize);
        self::assertSame(JobState::Queued, $summary->state);
        self::assertNull($this->stack->admin->findSummary(JobId::generate()));
    }

    public function test_a_job_changed_without_its_content_still_reads_it_on_demand(): void
    {
        $agent = $this->stack->register();
        $job = $this->stack->enqueue($agent, content: "^XA\x00Łódź^XZ");

        $cancelled = $this->stack->admin->cancel($job->id());

        self::assertSame("^XA\x00Łódź^XZ", $cancelled->content());
        self::assertSame(hash('sha256', "^XA\x00Łódź^XZ"), $cancelled->contentSha256());
        self::assertSame("^XA\x00Łódź^XZ", $this->stack->jobs->find($job->id())?->content(), 'a change never writes the content');
    }

    public function test_retention_closes_the_jobs_of_a_station_that_stopped_claiming(): void
    {
        $agent = $this->stack->register(['lease_s' => 10, 'spool_timeout_s' => 20]);
        $spooled = $this->stack->enqueue($agent, expiresIn: 60.0);
        $first = $this->stack->claimJob($agent);
        $this->stack->ack($agent, $first->id(), $first->token(), 'spooled');
        $leased = $this->stack->enqueue($agent, expiresIn: 60.0);
        $this->stack->claimJob($agent);
        // the station dies; nothing of it claims (and so sweeps) again
        $this->stack->clock->advance(3600);

        $purged = $this->stack->admin->purgeContent($this->stack->clock->now());

        self::assertSame(2, $purged);
        self::assertSame(JobState::Uncertain, $this->stack->jobs->find($spooled->id())?->state());
        self::assertSame(JobState::Queued, $this->stack->jobs->find($leased->id())?->state(), 'back in the queue, past its deadline: closed');
        self::assertSame(2, $this->stack->admin->purgeHistory($this->stack->clock->now()));
    }
}
