<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrintDock\Server\Adapter\InMemory\InMemoryDiscoveryStore;
use PrintDock\Server\Adapter\InMemory\InMemoryJobRepository;
use PrintDock\Server\Adapter\InMemory\MutableClock;
use PrintDock\Server\Application\PapServer;
use PrintDock\Server\Domain\Agent;
use PrintDock\Server\Domain\AgentActivity;
use PrintDock\Server\Domain\AgentId;
use PrintDock\Server\Domain\Release;
use PrintDock\Server\Http\HttpResponse;
use PrintDock\Server\Http\PapRouter;
use PrintDock\Server\Port\AgentRepository;
use PrintDock\Server\Port\ReleaseProvider;
use PrintDock\Server\Tests\Support\Stack;

/**
 * HTTP-level rules of section 1 and 12: headers, version handling, error bodies, content types.
 */
final class PapRouterTest extends TestCase
{
    private Stack $stack;

    protected function setUp(): void
    {
        $this->stack = Stack::inMemory();
    }

    /** @return array<string, mixed> */
    private function body(HttpResponse $response): array
    {
        return $response->decodedBody();
    }

    public function test_every_response_carries_x_pap_version_1_including_errors_and_204(): void
    {
        $agent = $this->stack->register();
        $this->stack->enqueue($agent);
        $claimed = $this->stack->claimJob($agent);
        $responses = [
            $this->stack->hello($agent),
            $this->stack->claim($agent),
            $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed'),
            $this->stack->claim($agent),
            $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'failed'),
            $this->stack->call('POST', '/hello', null),
            $this->stack->call('POST', '/hello', $agent->key, null, []),
            $this->stack->call('GET', '/no/such/path', $agent->key),
            $this->stack->call('POST', '/hello', $agent->key, ['not' => 'a hello']),
        ];

        self::assertSame([200, 200, 200, 204, 409, 401, 400, 404, 400], array_map(static fn (HttpResponse $r): int => $r->status, $responses));
        foreach ($responses as $response) {
            self::assertSame('1', $response->headers['X-PAP-Version']);
        }
    }

    public function test_a_request_without_x_pap_version_is_unsupported_version_even_with_a_bad_key(): void
    {
        $response = $this->stack->call('POST', '/hello', 'pa1_nope0000_bad', [], []);

        self::assertSame(400, $response->status);
        self::assertSame('unsupported_version', $this->body($response)['error']);
    }

    public function test_major_version_two_is_unsupported_and_a_minor_of_one_is_accepted(): void
    {
        $agent = $this->stack->register();

        $two = $this->stack->call('POST', '/jobs/claim', $agent->key, ['printer_ids' => ['p1']], ['X-PAP-Version' => '2']);
        $minor = $this->stack->call('POST', '/jobs/claim', $agent->key, ['printer_ids' => ['p1']], ['X-PAP-Version' => '1.4']);

        self::assertSame('unsupported_version', $this->body($two)['error']);
        self::assertSame(204, $minor->status);
    }

    public function test_a_missing_or_unknown_key_is_401_unauthorized(): void
    {
        self::assertSame('unauthorized', $this->body($this->stack->call('POST', '/hello', null, []))['error']);
        self::assertSame('unauthorized', $this->body($this->stack->call('POST', '/hello', 'pa1_nope0000_' . str_repeat('A', 43), []))['error']);
        self::assertSame('unauthorized', $this->body($this->stack->call('POST', '/hello', 'not-a-pap-key', []))['error']);
    }

    public function test_a_non_bearer_authorization_is_unauthorized(): void
    {
        $agent = $this->stack->register();

        $response = $this->stack->router->handle('POST', Stack::PREFIX . '/hello', ['X-PAP-Version' => '1', 'Authorization' => 'Basic ' . $agent->key], '{}');

        self::assertSame(401, $response->status);
    }

    public function test_unknown_paths_are_hidden_behind_authentication(): void
    {
        $agent = $this->stack->register();

        self::assertSame(401, $this->stack->call('GET', '/nope', null)->status);
        self::assertSame(404, $this->stack->call('GET', '/nope', $agent->key)->status);
    }

    public function test_a_wrong_method_on_a_known_path_is_404(): void
    {
        $agent = $this->stack->register();

        self::assertSame(404, $this->stack->call('GET', '/hello', $agent->key)->status);
        self::assertSame(404, $this->stack->call('POST', '/release', $agent->key, [])->status);
    }

    public function test_paths_outside_the_prefix_are_404_without_the_pap_header(): void
    {
        $response = $this->stack->router->handle('POST', '/other/hello', ['X-PAP-Version' => '1'], '{}');

        self::assertSame(404, $response->status);
        self::assertArrayNotHasKey('X-PAP-Version', $response->headers);
    }

    public function test_the_prefix_is_configurable_and_a_trailing_slash_is_ignored(): void
    {
        $agent = $this->stack->register();
        $router = new PapRouter($this->stack->server, '/api/printing/agent/v1/');

        $response = $router->handle('POST', '/api/printing/agent/v1/jobs/claim', ['x-pap-version' => '1', 'authorization' => 'Bearer ' . $agent->key], '{"printer_ids":["p1"]}');

        self::assertSame(204, $response->status);
        self::assertSame(404, $router->handle('POST', '/pap/v1/jobs/claim', [], '')->status);
    }

    public function test_header_names_are_case_insensitive_and_multi_value_headers_use_the_first_value(): void
    {
        $agent = $this->stack->register();

        $response = $this->stack->router->handle('POST', Stack::PREFIX . '/jobs/claim', ['X-Pap-VERSION' => ['1'], 'AUTHORIZATION' => ['Bearer ' . $agent->key]], '{"printer_ids":["p1"]}');

        self::assertSame(204, $response->status);
    }

    public function test_the_query_string_does_not_break_routing(): void
    {
        $agent = $this->stack->register();

        self::assertSame(204, $this->stack->call('GET', '/release?os=linux&arch=amd64&current=1.0.0', $agent->key)->status);
    }

    public function test_malformed_json_and_non_object_bodies_are_bad_request(): void
    {
        $agent = $this->stack->register();
        $headers = ['X-PAP-Version' => '1', 'Authorization' => 'Bearer ' . $agent->key];

        foreach (['{nope', '[1,2]', '"text"', '42'] as $body) {
            $response = $this->stack->router->handle('POST', Stack::PREFIX . '/jobs/claim', $headers, $body);
            self::assertSame(400, $response->status, $body);
            self::assertSame('bad_request', $this->body($response)['error']);
        }
    }

    public function test_every_error_body_has_error_message_and_server_time(): void
    {
        $agent = $this->stack->register();

        foreach ([
            $this->stack->call('POST', '/hello', null, []),
            $this->stack->call('POST', '/jobs/claim', $agent->key, ['printer_ids' => []]),
            $this->stack->ack($agent, 'pj_nope', 'lt_x', 'printed'),
            $this->stack->call('POST', '/hello', $agent->key, [], []),
        ] as $response) {
            $body = $this->body($response);
            self::assertIsString($body['error']);
            self::assertIsString($body['message']);
            self::assertIsString($body['server_time']);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $body['server_time']);
        }
    }

    public function test_every_json_response_carries_server_time(): void
    {
        $agent = $this->stack->register();
        $this->stack->enqueue($agent);
        $claimed = $this->stack->claimJob($agent);

        foreach ([
            $this->stack->hello($agent),
            $this->stack->extend($agent, $claimed->id(), $claimed->token()),
            $this->stack->ack($agent, $claimed->id(), $claimed->token(), 'printed'),
            $this->stack->call('POST', '/discovery', $agent->key, ['discovered_printers' => []]),
        ] as $response) {
            self::assertSame(200, $response->status);
            self::assertArrayHasKey('server_time', $this->body($response));
        }
        self::assertArrayHasKey('server_time', $claimed->body);
    }

    public function test_json_responses_are_application_json_and_204_has_no_content_type(): void
    {
        $agent = $this->stack->register();

        self::assertSame('application/json', $this->stack->hello($agent)->headers['Content-Type']);
        self::assertArrayNotHasKey('Content-Type', $this->stack->claim($agent)->headers);
    }

    public function test_unicode_is_sent_unescaped(): void
    {
        $agent = $this->stack->register();
        $this->stack->admin->enqueue(new \PrintDock\Server\Domain\NewJob($agent->agent->id(), 'p1', null, \PrintDock\Server\Domain\JobFormat::Zpl, '^XA^XZ', title: 'Zażółć gęślą jaźń'));

        $response = $this->stack->claim($agent);

        self::assertStringContainsString('Zażółć gęślą jaźń', $response->body);
    }

    public function test_title_and_reference_are_passed_through_and_omitted_when_absent(): void
    {
        $agent = $this->stack->register();
        $this->stack->admin->enqueue(new \PrintDock\Server\Domain\NewJob(
            $agent->agent->id(),
            'p1',
            'label',
            \PrintDock\Server\Domain\JobFormat::Zpl,
            '^XA^XZ',
            title: 'DPD 1',
            titleSensitive: true,
            reference: ['kind' => 'courier_label', 'source_ref' => 'x'],
        ));
        $withAll = $this->stack->claimJob($agent);
        $this->stack->ack($agent, $withAll->id(), $withAll->token(), 'printed');
        $this->stack->admin->enqueue(new \PrintDock\Server\Domain\NewJob($agent->agent->id(), 'p1', null, \PrintDock\Server\Domain\JobFormat::Zpl, '^XA^XZ'));
        $bare = $this->stack->claimJob($agent);

        self::assertSame('DPD 1', $withAll->body['title']);
        self::assertTrue($withAll->body['title_sensitive']);
        self::assertSame(['kind' => 'courier_label', 'source_ref' => 'x'], $withAll->body['reference']);
        self::assertSame('label', $withAll->body['printer_role']);
        foreach (['title', 'reference', 'printer_role'] as $absent) {
            self::assertArrayNotHasKey($absent, $bare->body);
        }
    }

    public function test_discovery_bodies_over_256_kib_are_bad_request(): void
    {
        $agent = $this->stack->register();
        $headers = ['X-PAP-Version' => '1', 'Authorization' => 'Bearer ' . $agent->key];

        $response = $this->stack->router->handle('POST', Stack::PREFIX . '/discovery', $headers, '{"discovered_printers":[],"pad":"' . str_repeat('x', 262144) . '"}');

        self::assertSame(400, $response->status);
    }

    public function test_release_offers_the_update_channels_answer_with_server_time(): void
    {
        $provider = new class () implements ReleaseProvider {
            public function releaseFor(string $os, string $arch, string $currentVersion): ?Release
            {
                return '0.0.0' === $currentVersion ? new Release('1.1.0', 'https://x/' . $os . '-' . $arch, 'abc', '1.0.0', 'sig') : null;
            }
        };
        $stack = new Stack($this->stack->clock, $this->stack->agents, $this->stack->jobs, $this->stack->discovery, releases: $provider);
        $agent = $stack->register();

        $update = $stack->call('GET', '/release?os=linux&arch=amd64&current=0.0.0', $agent->key);
        $none = $stack->call('GET', '/release?os=linux&arch=amd64&current=1.1.0', $agent->key);

        self::assertSame(200, $update->status);
        self::assertSame(['version' => '1.1.0', 'required_min' => '1.0.0', 'url' => 'https://x/linux-amd64', 'sha256' => 'abc', 'signature' => 'sig'], array_diff_key($this->body($update), ['server_time' => 1]));
        self::assertArrayHasKey('server_time', $this->body($update));
        self::assertSame(204, $none->status);
    }

    public function test_an_unexpected_exception_is_a_500_server_error_that_hides_the_message_and_reaches_the_hook(): void
    {
        $failing = new class () implements AgentRepository {
            public function save(Agent $agent): void
            {
            }

            public function find(AgentId $id): ?Agent
            {
                return null;
            }

            public function findByKeyId(string $keyId): ?Agent
            {
                throw new \RuntimeException('secret connection string');
            }

            public function all(): array
            {
                return [];
            }

            public function delete(AgentId $id): void
            {
            }

            public function recordHello(Agent $agent): void
            {
            }

            public function recordActivity(AgentId $id, AgentActivity $activity, \DateTimeImmutable $now): void
            {
            }

            public function recordClaimResult(AgentId $id, ?\DateTimeImmutable $emptyAt, array $printerIds = []): void
            {
            }
        };
        $server = new PapServer($failing, new InMemoryJobRepository(), new InMemoryDiscoveryStore(), new MutableClock());
        $seen = [];
        $router = new PapRouter($server, '/pap/v1', static function (\Throwable $e) use (&$seen): void {
            $seen[] = $e->getMessage();
        });

        $response = $router->handle('POST', '/pap/v1/hello', ['X-PAP-Version' => '1', 'Authorization' => 'Bearer pa1_abcd1234_' . str_repeat('A', 43)], '{}');

        self::assertSame(500, $response->status);
        self::assertSame('server_error', $this->body($response)['error']);
        self::assertStringNotContainsString('secret', $response->body);
        self::assertSame('1', $response->headers['X-PAP-Version']);
        self::assertSame(['secret connection string'], $seen);
    }
}
