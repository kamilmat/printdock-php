<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use PrintDock\Server\Http\Psr15Handler;
use PrintDock\Server\Tests\Support\Stack;

final class Psr15HandlerTest extends TestCase
{
    public function test_a_psr_7_request_is_served_through_the_router_and_back_as_a_psr_7_response(): void
    {
        $stack = Stack::inMemory();
        $agent = $stack->register();
        $stack->enqueue($agent);
        $factory = new Psr17Factory();
        $handler = new Psr15Handler($stack->router, $factory, $factory);
        $request = $factory->createServerRequest('POST', 'https://wms.test' . Stack::PREFIX . '/jobs/claim')
            ->withHeader('Authorization', 'Bearer ' . $agent->key)
            ->withHeader('X-PAP-Version', '1')
            ->withBody($factory->createStream('{"printer_ids":["p1"]}'));

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-PAP-Version'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('p1', $body['printer_id']);
    }

    public function test_a_204_has_an_empty_body_and_the_query_string_reaches_the_router(): void
    {
        $stack = Stack::inMemory();
        $agent = $stack->register();
        $factory = new Psr17Factory();
        $handler = new Psr15Handler($stack->router, $factory, $factory);

        $response = $handler->handle($factory->createServerRequest('GET', 'https://wms.test' . Stack::PREFIX . '/release?os=linux&arch=amd64&current=1')
            ->withHeader('Authorization', 'Bearer ' . $agent->key)
            ->withHeader('X-PAP-Version', '1'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }
}
