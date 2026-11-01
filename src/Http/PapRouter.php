<?php

declare(strict_types=1);

namespace PrintDock\Server\Http;

use PrintDock\Server\Application\PapServer;
use PrintDock\Server\Application\Request\AckRequest;
use PrintDock\Server\Application\Request\ClaimRequest;
use PrintDock\Server\Application\Request\ExtendRequest;
use PrintDock\Server\Application\Request\HelloRequest;
use PrintDock\Server\Application\Request\ReleaseQuery;
use PrintDock\Server\Domain\DiscoveryReport;
use PrintDock\Server\Domain\Input;
use PrintDock\Server\Domain\ProtocolError;

/**
 * Maps HTTP onto {@see PapServer}: routing, header and version checks, JSON in and out, the error catalogue.
 *
 * Mount it under a configurable prefix (`/pap/v1`, `/api/printing/agent/v1`). Every response under the prefix,
 * errors included, carries `X-PAP-Version: 1`. The version check comes first, then
 * authentication, and only then an unknown path becomes 404, so unauthenticated callers learn nothing about
 * the routes. Anything outside the prefix answers a plain 404 without the PAP header.
 */
final class PapRouter
{
    private const MAX_DISCOVERY_BODY = 262144;

    /** Default cap on any request body; far above a real `hello`, `claim` or `ack`. */
    public const DEFAULT_MAX_BODY = 524288;

    private readonly string $prefix;

    /** @var (callable(\Throwable): void)|null */
    private $onError;

    /**
     * @param (callable(\Throwable): void)|null $onError called for every unexpected exception, which then
     *                                                   becomes a 500 `server_error` without its message
     * @param int                               $maxBodyBytes larger bodies are refused with 400 `bad_request` (the catalogue
     *                                                        has no 413) once the caller is authenticated
     */
    public function __construct(
        private readonly PapServer $server,
        string $prefix = '/pap/v1',
        ?callable $onError = null,
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY,
    ) {
        $this->prefix = rtrim($prefix, '/');
        $this->onError = $onError;
    }

    /**
     * @param string                          $path    request target: path with an optional query string
     * @param array<string, string|list<string>> $headers any casing
     */
    public function handle(string $method, string $path, array $headers, string $body): HttpResponse
    {
        $url = parse_url($path);
        $urlPath = '/' . ltrim(rawurldecode($url['path'] ?? '/'), '/');
        $prefix = $this->prefix;
        if (!str_starts_with($urlPath, $prefix . '/') && $urlPath !== $prefix) {
            return HttpResponse::json(404, $this->errorBody(ProtocolError::notFound('no such path')));
        }

        try {
            $response = $this->dispatch(
                strtoupper($method),
                substr($urlPath, strlen($prefix)),
                $url['query'] ?? '',
                $this->normaliseHeaders($headers),
                $body,
            );
        } catch (ProtocolError $e) {
            $response = $this->errorResponse($e);
        } catch (\Throwable $e) {
            if (null !== $this->onError) {
                ($this->onError)($e);
            }
            $response = $this->errorResponse(ProtocolError::serverError());
        }

        return $response->withHeaders(['X-PAP-Version' => '1']);
    }

    /**
     * @param array<string, string> $headers lower-cased names
     */
    private function dispatch(string $method, string $rel, string $query, array $headers, string $body): HttpResponse
    {
        $this->server->checkVersion($headers['x-pap-version'] ?? null);
        $agent = $this->server->authenticate($headers['authorization'] ?? null);
        if (strlen($body) > $this->maxBodyBytes) {
            throw ProtocolError::badRequest(sprintf('body exceeds %d KiB', intdiv($this->maxBodyBytes, 1024)));
        }
        $parts = array_values(array_filter(explode('/', $rel), static fn (string $p): bool => '' !== $p));
        $count = count($parts);

        if ('POST' === $method && ['hello'] === $parts) {
            return $this->ok($this->server->hello($agent, $this->parse(fn (): HelloRequest => HelloRequest::fromArray($this->decode($body))))->toArray());
        }
        if ('POST' === $method && ['jobs', 'claim'] === $parts) {
            $claim = $this->server->claim($agent, $this->parse(fn (): ClaimRequest => ClaimRequest::fromArray($this->decode($body))));
            $payload = $claim->toArray();
            if (null !== $payload) {
                return $this->ok($payload);
            }
            $headers = ['Retry-After' => (string) $claim->retryAfterSeconds];
            if (null !== $claim->discover) {
                $headers['X-PAP-Discover'] = $claim->discover;
            }

            return new HttpResponse(204, $headers);
        }
        if (3 === $count && 'jobs' === $parts[0] && 'POST' === $method && 'ack' === $parts[2]) {
            return $this->ok($this->server->ack($agent, $parts[1], $this->parse(fn (): AckRequest => AckRequest::fromArray($this->decode($body))))->toArray());
        }
        if (3 === $count && 'jobs' === $parts[0] && 'POST' === $method && 'extend' === $parts[2]) {
            return $this->ok($this->server->extend($agent, $parts[1], $this->parse(fn (): ExtendRequest => ExtendRequest::fromArray($this->decode($body))))->toArray());
        }
        if (3 === $count && 'jobs' === $parts[0] && 'GET' === $method && 'content' === $parts[2]) {
            $content = $this->server->content($agent, $parts[1]);

            return new HttpResponse(200, [
                'Content-Type' => $content->contentType,
                'Content-Length' => (string) strlen($content->bytes),
            ], $content->bytes);
        }
        if ('GET' === $method && ['release'] === $parts) {
            parse_str($query, $params);
            $release = $this->server->release($agent, $this->parse(static fn (): ReleaseQuery => ReleaseQuery::fromArray(Input::toMap($params))));

            return null === $release ? new HttpResponse(204) : $this->ok($release);
        }
        if ('POST' === $method && ['discovery'] === $parts) {
            if (strlen($body) > self::MAX_DISCOVERY_BODY) {
                throw ProtocolError::badRequest('discovery body exceeds 256 KiB');
            }

            return $this->ok($this->server->discovery($agent, $this->parse(fn (): DiscoveryReport => DiscoveryReport::fromArray($this->decode($body))))->toArray());
        }

        throw ProtocolError::notFound('no such PAP path');
    }

    /**
     * Parses the request: only here is an invalid argument the agent's fault (400 with the message). The same
     * exception from anywhere else (stored data that no longer reads, say) is a server fault: 500 and `onError`.
     *
     * @template T
     *
     * @param callable(): T $parse
     *
     * @return T
     */
    private function parse(callable $parse): mixed
    {
        try {
            return $parse();
        } catch (\InvalidArgumentException $e) {
            throw ProtocolError::badRequest($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if ('' === $body) {
            return [];
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ProtocolError::badRequest('body is not valid JSON');
        }

        return Input::object($data);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function ok(array $payload): HttpResponse
    {
        return HttpResponse::json(200, $payload);
    }

    private function errorResponse(ProtocolError $e): HttpResponse
    {
        $headers = [];
        if (429 === $e->httpStatus()) {
            $retry = $e->extra['retry_after'] ?? 1;
            $headers['Retry-After'] = (string) (is_int($retry) ? $retry : 1);
        }

        return HttpResponse::json($e->httpStatus(), $this->errorBody($e), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorBody(ProtocolError $e): array
    {
        return [
            'error' => $e->errorCode->value,
            'message' => $e->getMessage(),
            'server_time' => $this->server->serverTime(),
        ] + array_diff_key($e->extra, ['retry_after' => true]);
    }

    /**
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, string>
     */
    private function normaliseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[strtolower($name)] = is_array($value) ? ($value[0] ?? '') : $value;
        }

        return $out;
    }
}
