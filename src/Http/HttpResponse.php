<?php

declare(strict_types=1);

namespace PrintDock\Server\Http;

use PrintDock\Server\Domain\Input;

/**
 * A framework-neutral HTTP response: status, headers and an already encoded body (empty for 204).
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json'] + $headers,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $headers + $this->headers, $this->body);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedBody(): array
    {
        return '' === $this->body ? [] : Input::object(json_decode($this->body, true, 64, JSON_THROW_ON_ERROR));
    }
}
