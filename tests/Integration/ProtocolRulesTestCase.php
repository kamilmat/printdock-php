<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PrintDock\Server\Application\PapServerConfig;
use PrintDock\Server\Application\RegisteredAgent;
use PrintDock\Server\Domain\AdminException;
use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\AgentSettings;
use PrintDock\Server\Domain\ContentDelivery;
use PrintDock\Server\Domain\JobFormat;
use PrintDock\Server\Domain\JobId;
use PrintDock\Server\Domain\JobState;
use PrintDock\Server\Domain\NewJob;
use PrintDock\Server\Domain\Printer;
use PrintDock\Server\Domain\PrinterTransport;
use PrintDock\Server\Domain\Time;
use PrintDock\Server\Tests\Support\ClaimedJob;
use PrintDock\Server\Tests\Support\CountingJobRepository;
use PrintDock\Server\Tests\Support\Stack;
use PrintDock\Server\Tests\Support\StaleReadAgentRepository;

/**
 * Every normative rule of PAP/1 that is visible on the wire, run against whichever adapters the subclass wires
 * in. The in-memory, SQLite and MySQL stacks must all pass the same tests: that is the adapter contract.
 */
abstract class ProtocolRulesTestCase extends TestCase
{
    use RepositoryContractTests;
    use HistoryContractTests;

    protected Stack $stack;

    abstract protected function createStack(): Stack;

    protected function setUp(): void
    {
        $this->stack = $this->createStack();
    }

    /** @return array<string, mixed> */
    private function agentSettings(): array
    {
        return ['lease_s' => 10, 'poll_interval_s' => 2, 'spool_timeout_s' => 20];
    }

    private function agent(): RegisteredAgent
    {
        return $this->stack->register($this->agentSettings());
    }

    /** @return array<string, mixed> */
    private function body(\PrintDock\Server\Http\HttpResponse $response): array
    {
        return $response->decodedBody();
    }

    private function claimed(RegisteredAgent $agent, string $content = '^XA^XZ'): ClaimedJob
    {
        $this->stack->enqueue($agent, content: $content);

        return $this->stack->claimJob($agent);
    }

    // -- 4. hello ----------------------------------------------------------------

    public function test_hello_returns_the_station_configuration_and_server_time(): void
    {
        $agent = $this->agent();

        $response = $this->stack->hello($agent);
        $body = $this->body($response);

        self::assertSame(200, $response->status);
        foreach (['agent_id', 'agent_name', 'poll_interval_s', 'hello_interval_s', 'lease_s', 'spool_timeout_s',
            'printers_managed_by', 'config_version', 'printers', 'server_capabilities', 'server_time'] as $field) {
            self::assertArrayHasKey($field, $body);
        }
        self::assertSame(10, $body['lease_s']);
        self::assertSame('server', $body['printers_managed_by']);
        self::assertSame(['long_poll_max_s' => 0, 'content_url' => true, 'enroll' => false, 'lease_extend' => true, 'discovery' => true], $body['server_capabilities']);
        self::assertSame([['printer_id', 'p1']], array_map(static fn (mixed $p): array => ['printer_id', is_array($p) ? $p['printer_id'] ?? null : null], is_array($body['printers']) ? $body['printers'] : []));
        self::assertIsString($body['server_time']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $body['server_time']);
    }

    public function test_hello_without_agent_or_capabilities_is_bad_request(): void
    {
        $agent = $this->agent();

        $response = $this->stack->call('POST', '/hello', $agent->key, ['agent' => ['version' => 'x']]);

        self::assertSame(400, $response->status);
        self::assertSame('bad_request', $this->body($response)['error']);
    }

    public function test_hello_ignores_unknown_fields(): void
    {
        $response = $this->stack->hello($this->agent(), ['from_the_future' => true]);

        self::assertSame(200, $response->status);
    }

    public function test_hello_with_an_unknown_agent_state_is_bad_request(): void
    {
        $response = $this->stack->hello($this->agent(), ['agent' => ['state' => 'sleeping']]);

        self::assertSame(400, $response->status);
    }

    public function test_agent_managed_configuration_is_recorded_from_hello_and_not_echoed_back(): void
    {
        $agent = $this->stack->register(['printers_managed_by' => 'agent', 'lease_s' => 10], []);
        $response = $this->stack->hello($agent, ['printers' => [
            ['printer_id' => 'local1', 'role' => 'label', 'language' => 'zpl', 'dpi' => 203, 'media' => '100x150mm'],
        ]]);

        self::assertSame([], $this->body($response)['printers']);
        $this->stack->enqueue($agent, 'local1');
        self::assertSame(200, $this->stack->claim($agent, ['local1'])->status);
    }

    public function test_agent_managed_hello_with_an_incomplete_printer_is_bad_request(): void
    {
        $agent = $this->stack->register(['printers_managed_by' => 'agent'], []);

        $response = $this->stack->hello($agent, ['printers' => [['printer_id' => 'local1']]]);

        self::assertSame(400, $response->status);
    }

    public function test_server_managed_configuration_ignores_printers_in_hello(): void
    {
        $agent = $this->agent();
        $this->stack->hello($agent, ['printers' => [
            ['printer_id' => 'sneaky', 'role' => 'label', 'language' => 'zpl', 'dpi' => 203, 'media' => '100x150mm'],
        ]]);

        self::assertSame(400, $this->stack->claim($agent, ['sneaky'])->status);
    }

    public function test_hello_records_what_the_agent_reported(): void
    {
        $agent = $this->agent();
        $this->stack->hello($agent, ['agent' => ['state' => 'paused'], 'last_error' => 'boom', 'spooled_jobs' => 2]);

        $stored = $this->stack->admin->findAgent($agent->agent->id());

        self::assertNotNull($stored);
        self::assertSame('paused', $stored->lastHello()?->state);
        self::assertSame('boom', $stored->lastHello()->lastError);
        self::assertSame(2, $stored->lastHello()->spooledJobs);
        self::assertSame(1, $stored->helloCount());
        self::assertNotNull($stored->lastHelloAt());
    }

    // -- 5. claim -----------------------------------------------------------------

    public function test_claim_with_empty_printer_ids_is_bad_request(): void
    {
        $response = $this->stack->claim($this->agent(), []);

        self::assertSame(400, $response->status);
        self::assertSame('bad_request', $this->body($response)['error']);
    }

    public function test_claim_with_only_unknown_printer_ids_is_bad_request(): void
    {
        self::assertSame(400, $this->stack->claim($this->agent(), ['no-such-printer'])->status);
    }

    public function test_claim_ignores_unknown_printer_ids_when_one_is_known(): void
    {
        $agent = $this->agent();
        $this->stack->enqueue($agent);

        self::assertSame(200, $this->stack->claim($agent, ['no-such-printer', 'p1'])->status);
    }

    public function test_claim_with_nothing_queued_is_204_with_retry_after_equal_to_the_poll_interval(): void
    {
        $response = $this->stack->claim($this->agent());

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame('2', $response->headers['Retry-After']);
        self::assertArrayNotHasKey('Content-Type', $response->headers);
    }

    public function test_retry_after_waits_until_the_next_job_becomes_due(): void
    {
        $agent = $this->agent();
        $this->stack->enqueue($agent, notBefore: 30.0);

        $response = $this->stack->claim($agent);

        self::assertSame(204, $response->status);
        self::assertSame('30', $response->headers['Retry-After']);
    }

    public function test_jobs_are_not_handed_out_before_their_not_before_time(): void
    {
        $agent = $this->agent();
        $this->stack->enqueue($agent, notBefore: 30.0);
        self::assertSame(204, $this->stack->claim($agent)->status);

        $this->stack->clock->advance(31);

        self::assertSame(200, $this->stack->claim($agent)->status);
    }

    public function test_the_oldest_queued_job_is_handed_out_first(): void
    {
        $agent = $this->agent();
        $first = $this->stack->enqueue($agent);
        $this->stack->enqueue($agent);

        self::assertSame($first->id()->value, $this->stack->claimJob($agent)->id());
    }

    public function test_a_claimed_job_carries_every_required_field(): void
    {
        $agent = $this->agent();
        $job = $this->stack->enqueue($agent, content: '^XA^FDhello^FS^XZ');
        $claimed = $this->stack->claimJob($agent);

        foreach (['job_id', 'printer_id', 'format', 'content_sha256', 'lease_token', 'lease_until', 'expires_at',
            'expires_in_s', 'attempt', 'redelivered', 'server_time', 'content_base64'] as $field) {
            self::assertArrayHasKey($field, $claimed->body);
        }
        self::assertArrayNotHasKey('content_url', $claimed->body);
        self::assertSame($job->id()->value, $claimed->id());
        self::assertSame('p1', $claimed->string('printer_id'));
        self::assertSame('zpl', $claimed->string('format'));
        self::assertSame(1, $claimed->int('attempt'));
        self::assertFalse($claimed->bool('redelivered'));
        self::assertSame('^XA^FDhello^FS^XZ', base64_decode($claimed->string('content_base64'), true));
        self::assertSame(hash('sha256', '^XA^FDhello^FS^XZ'), $claimed->string('content_sha256'));
        self::assertSame(900.0, $claimed->body['expires_in_s']);
    }

    public function test_options_are_sent_for_pdf_jobs_only(): void
    {
        $agent = $this->agent();
        $this->stack->enqueue($agent, format: JobFormat::Pdf, options: ['copies' => 2, 'fit' => 'page', 'rotate' => 0]);
        $pdf = $this->stack->claimJob($agent);
        $this->stack->ack($agent, $pdf->id(), $pdf->token(), 'printed');
        $this->stack->enqueue($agent, options: ['copies' => 2]);
        $zpl = $this->stack->claimJob($agent);

        self::assertSame(['copies' => 2, 'fit' => 'page', 'rotate' => 0], $pdf->body['options']);
        self::assertArrayNotHasKey('options', $zpl->body);
    }

    // -- 9.2 layout options ------------------------------------------------------

    /**
     * @param list<string>              $layout
     * @param array<string, mixed>|null $options
     */
    private function pdfClaim(RegisteredAgent $agent, array $layout, ?array $options = null): ClaimedJob
    {
        $this->stack->hello($agent, ['capabilities' => ['formats' => ['zpl', 'pdf'], 'layout' => $layout]]);
        $this->stack->enqueue($agent, format: JobFormat::Pdf, options: $options);
        $claimed = $this->stack->claimJob($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        return $claimed;
    }

    public function test_a_pdf_job_without_layout_options_is_stored_with_shrink_and_auto(): void
    {
        $job = $this->stack->enqueue($this->agent(), format: JobFormat::Pdf, options: ['copies' => 2]);

        self::assertSame(['copies' => 2, 'fit' => 'shrink', 'rotate' => 'auto'], $this->stack->jobs->find($job->id())?->options());
    }

    public function test_a_pdf_job_takes_missing_layout_options_from_its_printer(): void
    {
        $agent = $this->stack->admin->registerAgent('Packing 1', AgentSettings::fromArray([]), [
            new Printer('p1', 'a4', PrinterTransport::Spooler, 'driver', 300, '210x297mm', systemName: 'HP', fit: 'page', rotate: 90),
        ]);

        $fromPrinter = $this->stack->enqueue($agent, format: JobFormat::Pdf);
        $own = $this->stack->enqueue($agent, format: JobFormat::Pdf, options: ['fit' => 'none', 'rotate' => 0]);

        self::assertSame(['fit' => 'page', 'rotate' => 90], $fromPrinter->options());
        self::assertSame(['fit' => 'none', 'rotate' => 0], $own->options());
    }

    public function test_an_agent_that_announced_layout_receives_shrink_and_auto(): void
    {
        $claimed = $this->pdfClaim($this->agent(), ['shrink', 'auto_rotate']);

        self::assertSame(['fit' => 'shrink', 'rotate' => 'auto'], $claimed->body['options']);
    }

    public function test_an_agent_without_layout_capabilities_receives_page_and_zero_instead(): void
    {
        $agent = $this->agent();

        $none = $this->pdfClaim($agent, []);
        $onlyShrink = $this->pdfClaim($agent, ['shrink']);
        $explicit = $this->pdfClaim($agent, [], ['fit' => 'none', 'rotate' => 270]);

        self::assertSame(['fit' => 'page', 'rotate' => 0], $none->body['options']);
        self::assertSame(['fit' => 'shrink', 'rotate' => 0], $onlyShrink->body['options']);
        self::assertSame(['fit' => 'none', 'rotate' => 270], $explicit->body['options']);
        self::assertSame(['fit' => 'shrink', 'rotate' => 'auto'], $this->stack->jobs->find(JobId::fromString($none->id()))?->options(), 'the stored job keeps what was asked for');
    }

    public function test_invalid_pdf_options_are_refused_when_enqueueing(): void
    {
        $agent = $this->agent();
        foreach ([['fit' => 'stretch'], ['rotate' => 45], ['rotate' => 'left'], ['copies' => 0]] as $options) {
            try {
                $this->stack->enqueue($agent, format: JobFormat::Pdf, options: $options);
                self::fail('expected AdminException for ' . json_encode($options));
            } catch (AdminException $e) {
                self::assertSame(AdminException::BAD_REQUEST, $e->kind);
            }
        }
        $this->stack->enqueue($agent, options: ['fit' => 'stretch']);
        self::assertSame(1, count($this->stack->admin->history()->jobs), 'options of pass-through formats are ignored');
    }

    // -- claim rate limit ----------------------------------------------------------

    public function test_a_claim_too_soon_after_an_empty_one_is_429_with_the_seconds_left(): void
    {
        $agent = $this->agent();
        $this->stack->claim($agent);
        $this->stack->clock->advance(0.2);

        $limited = $this->stack->claim($agent);
        $this->stack->clock->advance(0.8);
        $allowed = $this->stack->claim($agent);

        self::assertSame(429, $limited->status);
        self::assertSame('rate_limited', $this->body($limited)['error']);
        self::assertSame('1', $limited->headers['Retry-After'], 'half of poll_interval_s 2, rounded up');
        self::assertSame(204, $allowed->status);
        self::assertSame(2, $this->stack->admin->findAgent($agent->agent->id())?->claimCount(), 'a refused claim is not counted');
    }

    public function test_new_work_or_a_hello_lifts_the_cooldown_and_a_host_can_turn_it_off(): void
    {
        $agent = $this->agent();
        $this->stack->claim($agent);
        $this->stack->enqueue($agent);
        // a job enqueued after the empty claim is handed out at once
        $job = new ClaimedJob($this->body($this->stack->claim($agent)));
        $this->stack->ack($agent, $job->id(), $job->token(), 'printed');
        self::assertSame(204, $this->stack->claim($agent)->status);
        $this->stack->hello($agent);
        self::assertSame(204, $this->stack->claim($agent)->status, 'a restarted station says hello first');

        $off = $this->stack->withConfig(new PapServerConfig(claimCooldown: false));
        $off->claim($agent);
        self::assertSame(204, $off->claim($agent)->status);
    }

    public function test_a_refused_claim_still_marks_the_station_as_seen(): void
    {
        $agent = $this->agent();
        $this->stack->claim($agent);
        $this->stack->clock->advance(0.2);

        self::assertSame(429, $this->stack->claim($agent)->status);

        $stored = $this->stack->admin->findAgent($agent->agent->id());
        self::assertNotNull($stored);
        self::assertEquals($this->stack->clock->now(), $stored->lastSeenAt());
        self::assertSame(1, $stored->claimCount());
    }

    public function test_new_work_lifts_the_cooldown_even_when_the_agent_was_read_before_the_empty_claim(): void
    {
        $agents = new StaleReadAgentRepository($this->stack->agents);
        $stack = new Stack($this->stack->clock, $agents, $this->stack->jobs, $this->stack->discovery);
        $agent = $stack->register($this->agentSettings());
        $agents->stale = $stack->agents->find($agent->agent->id());
        $stack->claim($agent);

        $stack->enqueue($agent);
        $agents->stale = null;

        self::assertSame(200, $stack->claim($agent)->status);
    }

    public function test_a_claim_offering_a_printer_that_has_just_become_free_is_not_limited(): void
    {
        $agent = $this->stack->register($this->agentSettings(), ['p1', 'p2']);
        $this->stack->enqueue($agent, 'p1');
        $job = $this->stack->claimJob($agent);
        // p1 prints; the station claims for p2 alone, then p1 frees up
        $this->stack->claim($agent, ['p2']);
        $this->stack->ack($agent, $job->id(), $job->token(), 'printed');

        self::assertSame(204, $this->stack->claim($agent, ['p1', 'p2'])->status, 'p1 was not in the empty claim');
        self::assertSame(429, $this->stack->claim($agent, ['p2'])->status);
    }

    public function test_the_claim_cooldown_is_at_least_half_a_second(): void
    {
        $agent = $this->stack->register(['poll_interval_s' => 1]);
        $this->stack->claim($agent);
        $this->stack->clock->advance(0.4);
        self::assertSame(429, $this->stack->claim($agent)->status);
        $this->stack->clock->advance(0.1);
        self::assertSame(204, $this->stack->claim($agent)->status);
    }

    public function test_a_claim_right_after_a_delivered_job_is_not_limited(): void
    {
        $agent = $this->agent();
        $this->stack->enqueue($agent);
        $this->stack->enqueue($agent);
        $first = $this->stack->claimJob($agent);
        $this->stack->ack($agent, $first->id(), $first->token(), 'printed');

        self::assertSame(200, $this->stack->claim($agent)->status);
        self::assertSame(200, $this->stack->claim($agent)->status, 'the redelivery of a leased job is a delivery too');
    }

    public function test_a_claim_after_a_long_poll_that_waited_is_not_limited(): void
    {
        $agent = $this->agent();
        $this->stack->claim($agent);
        $this->stack->clock->advance(1);
        $stack = $this->stack->withConfig(new PapServerConfig(null, 2), function (int $us): void {
            $this->stack->clock->advance($us / 1_000_000);
        });

        $first = $stack->claim($agent, waitS: 2.0);
        $second = $stack->claim($agent, waitS: 2.0);

        self::assertSame([204, 204], [$first->status, $second->status]);
        self::assertNull($this->stack->admin->findAgent($agent->agent->id())?->emptyClaimAt());
    }

    // -- 5.4.5 long polling ------------------------------------------------------

    public function test_without_long_polling_wait_s_is_ignored(): void
    {
        $agent = $this->agent();
        $slept = 0;
        $stack = $this->stack->withConfig(new PapServerConfig(), static function () use (&$slept): void {
            ++$slept;
        });

        $response = $stack->claim($agent, waitS: 20.0);

        self::assertSame(204, $response->status);
        self::assertSame(0, $slept);
    }

    public function test_a_long_poll_returns_as_soon_as_a_job_arrives(): void
    {
        $agent = $this->agent();
        $sleeps = [];
        $stack = $this->stack->withConfig(new PapServerConfig(null, 25), function (int $us) use (&$sleeps, $agent): void {
            $sleeps[] = $us;
            $this->stack->clock->advance($us / 1_000_000);
            if (3 === count($sleeps)) {
                $this->stack->enqueue($agent);
            }
        });
        $start = $this->stack->clock->now();

        $response = $stack->claim($agent, waitS: 20.0);

        self::assertSame(200, $response->status);
        self::assertSame([250_000, 250_000, 250_000], $sleeps);
        self::assertEqualsWithDelta(0.75, Time::secondsBetween($start, $this->stack->clock->now()), 0.001);
        $capabilities = $this->body($stack->hello($agent))['server_capabilities'];
        self::assertIsArray($capabilities);
        self::assertSame(25, $capabilities['long_poll_max_s']);
    }

    public function test_a_long_poll_without_work_answers_204_when_the_wait_ends_clamped_to_the_server_limit(): void
    {
        $agent = $this->agent();
        $slept = 0.0;
        $stack = $this->stack->withConfig(new PapServerConfig(null, 2), function (int $us) use (&$slept): void {
            $slept += $us / 1_000_000;
            $this->stack->clock->advance($us / 1_000_000);
        });

        $response = $stack->claim($agent, waitS: 30.0);

        self::assertSame(204, $response->status);
        self::assertEqualsWithDelta(2.0, $slept, 0.001);
        self::assertSame('2', $response->headers['Retry-After'] ?? null);
    }

    public function test_a_long_poll_sweeps_on_its_first_attempt_and_then_every_half_lease(): void
    {
        $agent = $this->agent();
        $counting = new CountingJobRepository($this->stack->jobs);
        $stack = new Stack($this->stack->clock, $this->stack->agents, $counting, $this->stack->discovery, new PapServerConfig(null, 25), null, function (int $us): void {
            $this->stack->clock->advance($us / 1_000_000);
        });

        self::assertSame(204, $stack->claim($agent, waitS: 20.0)->status);

        self::assertSame(5, $counting->sweeps, 'lease_s 10: at 0, 5, 10, 15 and 20 s, not 81 times');
    }

    public function test_a_long_poll_returns_early_when_a_scan_is_requested(): void
    {
        $agent = $this->agent();
        $this->stack->hello($agent, ['capabilities' => ['discovery' => true]]);
        $slept = 0;
        $stack = $this->stack->withConfig(new PapServerConfig(null, 10), function (int $us) use (&$slept, $agent): void {
            ++$slept;
            $this->stack->clock->advance($us / 1_000_000);
            $this->stack->admin->requestDiscovery($agent->agent->id());
        });

        $response = $stack->claim($agent, waitS: 10.0);

        self::assertSame(204, $response->status);
        self::assertSame(1, $slept);
        self::assertArrayHasKey('X-PAP-Discover', $response->headers);
    }

    public function test_a_leased_job_is_redelivered_before_new_work_with_the_same_token_and_attempt(): void
    {
        $agent = $this->agent();
        $first = $this->stack->enqueue($agent);
        $second = $this->stack->enqueue($agent);
        $claimed = $this->stack->claimJob($agent);
        $this->stack->clock->advance(4);

        $again = $this->stack->claimJob($agent);

        self::assertSame($first->id()->value, $again->id());
        self::assertTrue($again->bool('redelivered'));
        self::assertSame($claimed->token(), $again->token());
        self::assertSame($claimed->int('attempt'), $again->int('attempt'));
        self::assertNotSame($claimed->string('lease_until'), $again->string('lease_until'));
        $this->stack->ack($agent, $first->id()->value, $claimed->token(), 'printed');
        self::assertSame($second->id()->value, $this->stack->claimJob($agent)->id());
    }

    public function test_an_at_most_once_job_whose_lease_expires_is_closed_as_uncertain_and_never_handed_out_again(): void
    {
        $agent = $this->agent();
        $job = $this->stack->admin->enqueue(new NewJob($agent->agent->id(), 'p1', 'label', JobFormat::Zpl, '^XA^XZ', redeliverAfterLeaseExpiry: false));
        $claimed = $this->stack->claimJob($agent);
        $this->stack->clock->advance(11);

        self::assertSame(204, $this->stack->claim($agent)->status);

        $summary = $this->stack->admin->findSummary($job->id());
        self::assertNotNull($summary);
        self::assertSame(JobState::Uncertain, $summary->state);
        self::assertSame('lease_expired', $summary->reason?->value);
        self::assertNotNull($summary->finishedAt);
        self::assertFalse($summary->redeliverAfterLeaseExpiry);
        self::assertFalse($this->stack->admin->history()->jobs[0]->redeliverAfterLeaseExpiry);
        $events = $this->stack->admin->jobEvents($job->id());
        self::assertSame(['enqueued', 'leased', 'lease_expired'], array_map(static fn ($e): string => $e->type->value, $events));
        self::assertSame(JobState::Uncertain, $events[2]->stateAfter);
        self::assertSame(409, $this->stack->extend($agent, $claimed->id(), $claimed->token())->status);
        // the agent that did print it still has the last word (6.4.3)
        $ack = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');
        self::assertSame(200, $ack->status);
        self::assertSame(['printed', true], [$this->body($ack)['status'], $this->body($ack)['late']]);
    }

    public function test_jobs_are_redelivered_after_lease_expiry_by_default(): void
    {
        $agent = $this->agent();
        $job = $this->stack->enqueue($agent);
        $this->stack->claimJob($agent);
        $this->stack->clock->advance(11);

        self::assertSame(2, $this->stack->claimJob($agent)->int('attempt'));
        self::assertTrue($this->stack->admin->findSummary($job->id())?->redeliverAfterLeaseExpiry);
    }

    public function test_spooled_jobs_are_never_redelivered(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'spooled', ['spool_job_id' => '42']);
        $this->stack->clock->advance(11);

        self::assertSame(204, $this->stack->claim($agent)->status);
    }

    public function test_a_spooled_job_does_not_block_newer_jobs(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'spooled');
        $newer = $this->stack->enqueue($agent);

        $next = $this->stack->claimJob($agent);

        self::assertSame($newer->id()->value, $next->id());
        self::assertFalse($next->bool('redelivered'));
    }

    public function test_a_spooled_job_is_closed_by_the_final_ack(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'spooled');
        $this->stack->clock->advance(11);

        $response = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        self::assertSame('printed', $this->body($response)['status']);
    }

    public function test_an_expired_lease_returns_the_job_to_the_queue_with_a_new_token_and_attempt_two(): void
    {
        $agent = $this->agent();
        $first = $this->claimed($agent);
        $this->stack->clock->advance(11);

        $second = $this->stack->claimJob($agent);

        self::assertSame($first->id(), $second->id());
        self::assertFalse($second->bool('redelivered'));
        self::assertSame(2, $second->int('attempt'));
        self::assertNotSame($first->token(), $second->token());
    }

    public function test_an_ack_with_the_token_of_an_earlier_lease_is_accepted_and_late(): void
    {
        $agent = $this->agent();
        $first = $this->claimed($agent);
        $this->stack->clock->advance(11);
        $second = $this->stack->claimJob($agent);

        $late = $this->stack->ack($agent, $first->id(), $first->token(), 'printed');
        $again = $this->stack->ack($agent, $second->id(), $second->token(), 'printed');

        self::assertSame(200, $late->status);
        self::assertTrue($this->body($late)['late']);
        self::assertSame('printed', $this->body($again)['status']);
    }

    public function test_expired_queued_jobs_are_never_handed_out(): void
    {
        $agent = $this->agent();
        $this->stack->enqueue($agent, expiresIn: 5.0);
        $this->stack->clock->advance(6);

        self::assertSame(204, $this->stack->claim($agent)->status);
    }

    // -- 6. ack -------------------------------------------------------------------

    public function test_ack_printed_closes_the_job_and_a_repeat_is_idempotent(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);

        $first = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed', ['detail' => 'socket x, 1 B']);
        $repeat = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        self::assertSame(['printed', false], [$this->body($first)['status'], $this->body($first)['late']]);
        self::assertSame(200, $repeat->status);
        self::assertSame('printed', $this->body($repeat)['status']);
        self::assertSame(204, $this->stack->claim($agent)->status);
    }

    public function test_ack_with_a_different_final_outcome_is_409_conflict_carrying_the_status(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'failed', ['detail' => 'boom']);

        $response = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        self::assertSame(409, $response->status);
        self::assertSame('conflict', $this->body($response)['error']);
        self::assertSame('failed', $this->body($response)['status']);
    }

    public function test_ack_on_an_unknown_job_is_404(): void
    {
        $response = $this->stack->ack($this->agent(), 'pj_does_not_exist', 'lt_x', 'printed');

        self::assertSame(404, $response->status);
        self::assertSame('not_found', $this->body($response)['error']);
    }

    public function test_ack_on_a_job_of_another_agent_is_404(): void
    {
        $owner = $this->agent();
        $other = $this->agent();
        $claimed = $this->claimed($owner);

        self::assertSame(404, $this->stack->ack($other, $claimed->id(), $claimed->token(), 'printed')->status);
    }

    public function test_ack_with_a_token_never_issued_for_the_job_is_403_bad_lease_token(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);

        $response = $this->stack->ack($agent, $claimed->id(), 'lt_never_issued', 'printed');

        self::assertSame(403, $response->status);
        self::assertSame('bad_lease_token', $this->body($response)['error']);
    }

    public function test_ack_without_a_lease_token_is_bad_request(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);

        self::assertSame(400, $this->stack->ack($agent, $claimed->id(), null, 'printed')->status);
    }

    public function test_ack_with_an_unknown_outcome_is_bad_request(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);

        self::assertSame(400, $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'exploded')->status);
    }

    public function test_rejected_needs_a_reason_and_is_stored_as_failed(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);

        $without = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'rejected');
        $with = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'rejected', ['reason' => 'unsupported_route', 'detail' => 'pdf on epl']);

        self::assertSame(400, $without->status);
        self::assertSame('failed', $this->body($with)['status']);
        $job = $this->stack->admin->findJob(JobId::fromString($claimed->id()));
        self::assertSame('unsupported_route', $job?->reason()?->value);
        self::assertSame('pdf on epl', $job->detail());
    }

    public function test_rejected_after_failed_is_an_idempotent_repeat_because_both_mean_failed(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'failed');

        $response = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'rejected', ['reason' => 'expired']);

        self::assertSame(200, $response->status);
        self::assertSame('failed', $this->body($response)['status']);
    }

    public function test_any_well_formed_reason_code_is_accepted_and_a_malformed_one_is_bad_request(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);

        $bad = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'failed', ['reason' => 'Not A Code!']);
        $ok = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'failed', ['reason' => 'paper_out_of_this_world', 'detail' => 'verbatim OS text']);

        self::assertSame(400, $bad->status);
        self::assertSame(200, $ok->status);
        $job = $this->stack->admin->findJob(JobId::fromString($claimed->id()));
        self::assertSame('paper_out_of_this_world', $job?->reason()?->value);
    }

    public function test_a_printed_ack_after_expires_at_is_recorded_and_late(): void
    {
        $agent = $this->agent();
        $this->stack->enqueue($agent, expiresIn: 5.0);
        $claimed = $this->stack->claimJob($agent);
        $this->stack->clock->advance(6);

        $response = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        self::assertSame('printed', $this->body($response)['status']);
        self::assertTrue($this->body($response)['late']);
    }

    public function test_an_ack_on_a_requeued_job_is_applied_and_late(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->clock->advance(11);
        $this->stack->claim($agent);
        $this->stack->clock->advance(0);

        $response = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        self::assertSame('printed', $this->body($response)['status']);
        self::assertTrue($this->body($response)['late']);
    }

    public function test_a_repeated_spooled_ack_keeps_the_job_spooled(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'spooled');

        $response = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'spooled');

        self::assertSame(200, $response->status);
        self::assertSame('spooled', $this->body($response)['status']);
    }

    public function test_a_spooled_job_without_a_final_ack_becomes_uncertain_never_queued(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'spooled');
        $this->stack->clock->advance(10 + 20 + 1);

        self::assertSame(204, $this->stack->claim($agent)->status);
        $job = $this->stack->admin->findJob(JobId::fromString($claimed->id()));
        self::assertSame('uncertain', $job?->state()->value);
    }

    public function test_an_agent_that_knows_beats_a_server_that_guessed_uncertain(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'spooled');
        $this->stack->clock->advance(31);
        $this->stack->claim($agent);

        $response = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        self::assertSame('printed', $this->body($response)['status']);
        self::assertTrue($this->body($response)['late']);
    }

    public function test_a_cancelled_job_is_never_handed_out_and_acks_on_it_are_409(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->admin->cancel(JobId::fromString($claimed->id()));

        $response = $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        self::assertSame(409, $response->status);
        self::assertSame('cancelled', $this->body($response)['status']);
        self::assertSame(204, $this->stack->claim($agent)->status);
    }

    public function test_cancelling_only_a_queued_job_is_refused_once_a_station_has_taken_it(): void
    {
        $agent = $this->agent();
        $taken = $this->claimed($agent);
        $waiting = $this->stack->enqueue($agent);

        try {
            $this->stack->admin->cancel(JobId::fromString($taken->id()), JobState::Queued);
            self::fail('a leased job must not be cancelled as queued');
        } catch (AdminException $e) {
            self::assertSame(AdminException::CONFLICT, $e->kind);
        }
        self::assertSame(JobState::Leased, $this->stack->jobs->find(JobId::fromString($taken->id()))?->state());
        self::assertSame(['enqueued', 'leased'], array_map(static fn ($e): string => $e->type->value, $this->stack->admin->jobEvents(JobId::fromString($taken->id()))));
        self::assertSame(JobState::Cancelled, $this->stack->admin->cancel($waiting->id(), JobState::Queued)->state());
    }

    public function test_a_queued_job_that_is_cancelled_is_never_handed_out(): void
    {
        $agent = $this->agent();
        $job = $this->stack->enqueue($agent);
        $this->stack->admin->cancel($job->id());

        self::assertSame(204, $this->stack->claim($agent)->status);
    }

    // -- 5.6 extend ---------------------------------------------------------------

    public function test_extend_keeps_a_job_out_of_the_queue_past_the_original_lease(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        for ($i = 0; $i < 3; ++$i) {
            $this->stack->clock->advance(6);
            $response = $this->stack->extend($agent, $claimed->id(), $claimed->token());
            self::assertSame(200, $response->status);
            self::assertArrayHasKey('lease_until', $this->body($response));
        }

        $next = $this->stack->claimJob($agent);

        self::assertTrue($next->bool('redelivered'));
        self::assertSame($claimed->token(), $next->token());
        self::assertSame(1, $next->int('attempt'));
    }

    public function test_extend_with_a_token_never_issued_is_403(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);

        $response = $this->stack->extend($agent, $claimed->id(), 'lt_neverissued');

        self::assertSame(403, $response->status);
        self::assertSame('bad_lease_token', $this->body($response)['error']);
    }

    public function test_extend_after_the_final_ack_is_409_with_the_status(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        $response = $this->stack->extend($agent, $claimed->id(), $claimed->token());

        self::assertSame(409, $response->status);
        self::assertSame('printed', $this->body($response)['status']);
    }

    public function test_extend_on_an_unknown_job_or_one_of_another_agent_is_404(): void
    {
        $owner = $this->agent();
        $other = $this->agent();
        $claimed = $this->claimed($owner);

        self::assertSame(404, $this->stack->extend($owner, 'nosuchjob', $claimed->token())->status);
        self::assertSame(404, $this->stack->extend($other, $claimed->id(), $claimed->token())->status);
    }

    public function test_extend_after_the_lease_lapsed_is_409_queued_and_does_not_revive_the_lease(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimed($agent);
        $this->stack->clock->advance(11);

        $response = $this->stack->extend($agent, $claimed->id(), $claimed->token());

        self::assertSame(409, $response->status);
        self::assertSame('queued', $this->body($response)['status']);
    }

    public function test_extend_with_the_token_of_a_superseded_lease_is_409(): void
    {
        $agent = $this->agent();
        $first = $this->claimed($agent);
        $this->stack->clock->advance(11);
        $this->stack->claimJob($agent);

        self::assertSame(409, $this->stack->extend($agent, $first->id(), $first->token())->status);
    }

    // -- 7. content ---------------------------------------------------------------

    private function claimedByUrl(RegisteredAgent $agent): ClaimedJob
    {
        $this->stack->admin->enqueue(new NewJob(
            AgentId::fromString($agent->agent->id()->value),
            'p1',
            'label',
            JobFormat::Pdf,
            '%PDF-1.4 fake',
            delivery: ContentDelivery::Url,
        ));

        return $this->stack->claimJob($agent);
    }

    public function test_content_url_delivery_sends_a_url_on_the_servers_origin_instead_of_inline_bytes(): void
    {
        $claimed = $this->claimedByUrl($this->agent());

        self::assertArrayNotHasKey('content_base64', $claimed->body);
        self::assertSame('http://pap.test/pap/v1/jobs/' . $claimed->id() . '/content', $claimed->string('content_url'));
    }

    public function test_content_is_served_to_the_agent_that_holds_the_lease(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimedByUrl($agent);

        $response = $this->stack->call('GET', '/jobs/' . $claimed->id() . '/content', $agent->key);

        self::assertSame(200, $response->status);
        self::assertSame('%PDF-1.4 fake', $response->body);
        self::assertSame('application/pdf', $response->headers['Content-Type']);
        self::assertSame('13', $response->headers['Content-Length']);
        self::assertSame(hash('sha256', $response->body), $claimed->string('content_sha256'));
    }

    public function test_content_stays_available_while_the_job_is_spooled_and_more_than_once(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimedByUrl($agent);
        $this->stack->call('GET', '/jobs/' . $claimed->id() . '/content', $agent->key);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'spooled');

        self::assertSame(200, $this->stack->call('GET', '/jobs/' . $claimed->id() . '/content', $agent->key)->status);
    }

    public function test_content_is_404_after_a_final_state(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimedByUrl($agent);
        $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed');

        self::assertSame(404, $this->stack->call('GET', '/jobs/' . $claimed->id() . '/content', $agent->key)->status);
    }

    public function test_content_is_404_for_a_queued_job_and_for_another_agent(): void
    {
        $owner = $this->agent();
        $other = $this->agent();
        $claimed = $this->claimedByUrl($owner);
        $queued = $this->stack->enqueue($owner);

        self::assertSame(404, $this->stack->call('GET', '/jobs/' . $queued->id()->value . '/content', $owner->key)->status);
        self::assertSame(404, $this->stack->call('GET', '/jobs/' . $claimed->id() . '/content', $other->key)->status);
    }

    public function test_content_is_404_once_the_lease_lapsed(): void
    {
        $agent = $this->agent();
        $claimed = $this->claimedByUrl($agent);
        $this->stack->clock->advance(11);

        self::assertSame(404, $this->stack->call('GET', '/jobs/' . $claimed->id() . '/content', $agent->key)->status);
    }

    // -- 2. authentication --------------------------------------------------------

    public function test_a_revoked_key_is_401_revoked_and_works_again_once_restored(): void
    {
        $agent = $this->agent();
        $this->stack->admin->updateAgent($agent->agent->id(), static fn ($a) => $a->revoke());

        $revoked = $this->stack->hello($agent);
        $this->stack->admin->updateAgent($agent->agent->id(), static fn ($a) => $a->restore());

        self::assertSame(401, $revoked->status);
        self::assertSame('revoked', $this->body($revoked)['error']);
        self::assertSame(200, $this->stack->hello($agent)->status);
    }

    public function test_a_key_with_a_wrong_secret_is_unauthorized(): void
    {
        $agent = $this->agent();
        $forged = substr($agent->key, 0, 13) . str_repeat('A', 43);

        $response = $this->stack->call('POST', '/hello', $forged, []);

        self::assertSame(401, $response->status);
        self::assertSame('unauthorized', $this->body($response)['error']);
    }

    public function test_the_stored_key_is_a_hash_and_not_the_secret(): void
    {
        $agent = $this->agent();

        $data = $this->stack->admin->findAgent($agent->agent->id())?->toData();

        self::assertNotNull($data);
        self::assertStringNotContainsString(substr($agent->key, 14), $data['key_hash']);
        self::assertSame(64, strlen($data['key_hash']));
    }

    // -- 8. release ---------------------------------------------------------------

    public function test_release_without_an_update_channel_is_204(): void
    {
        $response = $this->stack->call('GET', '/release?os=linux&arch=amd64&current=0.0.0', $this->agent()->key);

        self::assertSame(204, $response->status);
    }

    // -- 14. discovery ------------------------------------------------------------

    /** @return array<string, mixed> */
    private function discoveryResult(?string $requestId): array
    {
        return ['request_id' => $requestId, 'agent_time' => '2026-01-01T00:00:00Z', 'duration_s' => 3.0, 'subnets' => ['10.0.0.0/24'],
            'hosts_probed' => 253, 'truncated' => false, 'error' => null,
            'discovered_printers' => [['address' => '10.0.0.15', 'ports' => [9100], 'model' => 'Zebra ZD421', 'already_configured' => true]]];
    }

    public function test_a_discovery_request_rides_on_hello_and_on_claim_204_until_a_result_closes_it(): void
    {
        $agent = $this->agent();
        $discovering = ['capabilities' => ['discovery' => true]];
        $this->stack->hello($agent, $discovering);
        self::assertArrayNotHasKey('discover', $this->body($this->stack->hello($agent, $discovering)));

        $requestId = $this->stack->admin->requestDiscovery($agent->agent->id());

        self::assertSame($requestId, $this->body($this->stack->hello($agent, $discovering))['discover']);
        $claim = $this->stack->claim($agent);
        self::assertSame(204, $claim->status);
        self::assertSame($requestId, $claim->headers['X-PAP-Discover']);

        $stored = $this->stack->call('POST', '/discovery', $agent->key, $this->discoveryResult($requestId));

        self::assertSame(['stored', true], [$this->body($stored)['status'], $this->body($stored)['matched']]);
        self::assertArrayNotHasKey('discover', $this->body($this->stack->hello($agent, $discovering)));
        self::assertArrayNotHasKey('X-PAP-Discover', $this->stack->claim($agent)->headers);
    }

    public function test_a_discovery_request_rides_on_a_claim_200_too(): void
    {
        $agent = $this->agent();
        $this->stack->hello($agent, ['capabilities' => ['discovery' => true]]);
        $requestId = $this->stack->admin->requestDiscovery($agent->agent->id());
        $this->stack->enqueue($agent);

        self::assertSame($requestId, $this->stack->claimJob($agent)->string('discover'));
    }

    public function test_discovery_is_only_offered_to_agents_that_announced_the_capability(): void
    {
        $agent = $this->agent();
        $this->stack->hello($agent);

        $this->expectException(\PrintDock\Server\Domain\AdminException::class);
        $this->stack->admin->requestDiscovery($agent->agent->id());
    }

    public function test_a_repeated_discovery_result_is_stored_again_and_not_matched(): void
    {
        $agent = $this->agent();
        $this->stack->hello($agent, ['capabilities' => ['discovery' => true]]);
        $requestId = $this->stack->admin->requestDiscovery($agent->agent->id());
        $result = $this->discoveryResult($requestId);

        $first = $this->stack->call('POST', '/discovery', $agent->key, $result);
        $repeat = $this->stack->call('POST', '/discovery', $agent->key, $result);

        self::assertTrue($this->body($first)['matched']);
        self::assertSame(200, $repeat->status);
        self::assertFalse($this->body($repeat)['matched']);
        self::assertNull($this->stack->admin->openDiscoveryRequest($agent->agent->id()));
        $latest = $this->stack->admin->latestDiscovery($agent->agent->id());
        self::assertNotNull($latest);
        $printers = $latest->result['discovered_printers'] ?? null;
        self::assertIsArray($printers);
        self::assertSame('10.0.0.15', is_array($printers[0] ?? null) ? $printers[0]['address'] ?? null : null);
    }

    public function test_a_result_for_another_request_id_does_not_close_the_open_request(): void
    {
        $agent = $this->agent();
        $this->stack->hello($agent, ['capabilities' => ['discovery' => true]]);
        $requestId = $this->stack->admin->requestDiscovery($agent->agent->id());

        $response = $this->stack->call('POST', '/discovery', $agent->key, $this->discoveryResult(null));

        self::assertFalse($this->body($response)['matched']);
        self::assertSame($requestId, $this->stack->admin->openDiscoveryRequest($agent->agent->id()));
    }

    public function test_malformed_discovery_results_are_bad_request(): void
    {
        $agent = $this->agent();

        foreach ([
            ['discovered_printers' => 'nope'],
            ['discovered_printers' => [['ports' => [9100], 'already_configured' => false]]],
            ['discovered_printers' => [['address' => '10.0.0.1', 'ports' => [9100]]]],
            ['discovered_printers' => [], 'request_id' => 5],
            ['discovered_printers' => array_fill(0, 257, ['address' => '10.0.0.1', 'ports' => [], 'already_configured' => false])],
        ] as $bad) {
            self::assertSame(400, $this->stack->call('POST', '/discovery', $agent->key, $bad)->status);
        }
    }

    // -- activity -----------------------------------------------------------------

    public function test_claims_and_other_requests_mark_the_agent_as_seen(): void
    {
        $agent = $this->agent();
        $this->stack->claim($agent);
        $this->stack->clock->advance(1);
        $this->stack->claim($agent);
        $this->stack->clock->advance(5);
        $this->stack->call('GET', '/release', $agent->key);

        $stored = $this->stack->admin->findAgent($agent->agent->id());

        self::assertSame(2, $stored?->claimCount());
        self::assertNotNull($stored->lastClaimAt());
        self::assertGreaterThan($stored->lastClaimAt(), $stored->lastSeenAt());
    }
}
