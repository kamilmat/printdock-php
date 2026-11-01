<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Support;

use PrintDock\Server\Adapter\InMemory\InMemoryAgentRepository;
use PrintDock\Server\Adapter\InMemory\InMemoryDiscoveryStore;
use PrintDock\Server\Adapter\InMemory\InMemoryJobRepository;
use PrintDock\Server\Adapter\InMemory\MutableClock;
use PrintDock\Server\Application\PapAdmin;
use PrintDock\Server\Application\PapServer;
use PrintDock\Server\Application\PapServerConfig;
use PrintDock\Server\Application\RegisteredAgent;
use PrintDock\Server\Domain\AgentSettings;
use PrintDock\Server\Domain\Input;
use PrintDock\Server\Domain\Job;
use PrintDock\Server\Domain\JobFormat;
use PrintDock\Server\Domain\NewJob;
use PrintDock\Server\Domain\Printer;
use PrintDock\Server\Domain\PrinterTransport;
use PrintDock\Server\Http\HttpResponse;
use PrintDock\Server\Http\PapRouter;
use PrintDock\Server\Port\AgentRepository;
use PrintDock\Server\Port\DiscoveryStore;
use PrintDock\Server\Port\JobRepository;
use PrintDock\Server\Port\ReleaseProvider;

/**
 * A fully wired server over whichever adapters a test passes in, plus helpers that speak PAP through the router.
 */
final class Stack
{
    public const PREFIX = '/pap/v1';

    public readonly PapServer $server;
    public readonly PapAdmin $admin;
    public readonly PapRouter $router;

    /**
     * @param (\Closure(int): void)|null $sleep
     */
    public function __construct(
        public readonly MutableClock $clock,
        public readonly AgentRepository $agents,
        public readonly JobRepository $jobs,
        public readonly DiscoveryStore $discovery,
        ?PapServerConfig $config = null,
        ?ReleaseProvider $releases = null,
        ?\Closure $sleep = null,
    ) {
        $config ??= new PapServerConfig('http://pap.test' . self::PREFIX);
        $this->server = new PapServer($agents, $jobs, $discovery, $clock, $config, $releases, $sleep);
        $this->admin = new PapAdmin($agents, $jobs, $discovery, $clock, $config);
        $this->router = new PapRouter($this->server, self::PREFIX);
    }

    public static function inMemory(): self
    {
        return new self(new MutableClock(), new InMemoryAgentRepository(), new InMemoryJobRepository(), new InMemoryDiscoveryStore());
    }

    /**
     * The same storage and clock behind a server with another configuration (long polling, for example).
     *
     * @param (\Closure(int): void)|null $sleep
     */
    public function withConfig(PapServerConfig $config, ?\Closure $sleep = null): self
    {
        return new self($this->clock, $this->agents, $this->jobs, $this->discovery, $config, null, $sleep);
    }

    /**
     * @param array<string, mixed> $settings keys of {@see AgentSettings::fromArray()}
     * @param list<string>         $printerIds
     */
    public function register(array $settings = [], array $printerIds = ['p1']): RegisteredAgent
    {
        $printers = array_map(
            static fn (string $id): Printer => new Printer($id, 'label', PrinterTransport::Socket, 'zpl', 203, '100x150mm', '10.0.0.15:9100'),
            $printerIds,
        );

        return $this->admin->registerAgent('Packing 1', AgentSettings::fromArray($settings), $printers);
    }

    /**
     * @param array<string, mixed>|null $options
     */
    public function enqueue(RegisteredAgent $agent, string $printerId = 'p1', string $content = '^XA^XZ', float $expiresIn = 900.0, float $notBefore = 0.0, JobFormat $format = JobFormat::Zpl, ?array $options = null): Job
    {
        return $this->admin->enqueue(new NewJob(
            $agent->agent->id(),
            $printerId,
            'label',
            $format,
            $content,
            options: $options,
            title: 'DPD 123',
            notBeforeInSeconds: $notBefore,
            expiresInSeconds: $expiresIn,
        ));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    public function call(string $method, string $path, ?string $key, ?array $body = null, array $headers = ['X-PAP-Version' => '1']): HttpResponse
    {
        if (null !== $key) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        return $this->router->handle($method, self::PREFIX . $path, $headers, null === $body ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function hello(RegisteredAgent $agent, array $extra = []): HttpResponse
    {
        return $this->call('POST', '/hello', $agent->key, Input::toMap(array_replace_recursive([
            'agent' => ['version' => '1.0.0', 'os' => 'linux', 'arch' => 'amd64', 'hostname' => 'PACK-1'],
            'capabilities' => ['transports' => ['socket'], 'formats' => ['zpl', 'raw']],
        ], $extra)));
    }

    /**
     * @param list<string> $printerIds
     */
    public function claim(RegisteredAgent $agent, array $printerIds = ['p1'], float $waitS = 0.0): HttpResponse
    {
        return $this->call('POST', '/jobs/claim', $agent->key, ['printer_ids' => $printerIds, 'max' => 1, 'wait_s' => $waitS]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function ack(RegisteredAgent $agent, string $jobId, ?string $token, string $outcome, array $extra = []): HttpResponse
    {
        return $this->call('POST', '/jobs/' . $jobId . '/ack', $agent->key, ['lease_token' => $token, 'outcome' => $outcome] + $extra);
    }

    public function extend(RegisteredAgent $agent, string $jobId, ?string $token): HttpResponse
    {
        return $this->call('POST', '/jobs/' . $jobId . '/extend', $agent->key, ['lease_token' => $token]);
    }

    /** Claims and returns the decoded job; fails the test when the claim is not a 200. */
    public function claimJob(RegisteredAgent $agent): ClaimedJob
    {
        $response = $this->claim($agent);
        if (200 !== $response->status) {
            throw new \RuntimeException('expected a job, got HTTP ' . $response->status . ' ' . $response->body);
        }

        return new ClaimedJob($response->decodedBody());
    }
}
