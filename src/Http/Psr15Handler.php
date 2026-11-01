<?php

declare(strict_types=1);

namespace PrintDock\Server\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 wrapper around {@see PapRouter}. Needs psr/http-server-handler and a PSR-17 factory pair, which are
 * suggestions, not requirements, of this package.
 */
final class Psr15Handler implements RequestHandlerInterface
{
    public function __construct(
        private readonly PapRouter $router,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $target = $uri->getPath() . ('' === $uri->getQuery() ? '' : '?' . $uri->getQuery());
        /** @var array<string, list<string>> $headers */
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        $result = $this->router->handle($request->getMethod(), $target, $headers, (string) $request->getBody());

        $response = $this->responses->createResponse($result->status);
        foreach ($result->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return '' === $result->body ? $response : $response->withBody($this->streams->createStream($result->body));
    }
}
