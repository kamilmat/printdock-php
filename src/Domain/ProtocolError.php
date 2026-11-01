<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * A protocol-level failure that maps one-to-one onto an entry of the error catalogue.
 *
 * Thrown by the domain and the application service; the HTTP adapter turns it into the error body of section 12.
 * `$extra` carries the code-specific fields (`status` on a conflict, `retry_after` on rate limiting).
 */
final class ProtocolError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->errorCode->httpStatus();
    }

    public static function badRequest(string $message): self
    {
        return new self(ErrorCode::BadRequest, $message);
    }

    public static function unsupportedVersion(): self
    {
        return new self(ErrorCode::UnsupportedVersion, 'X-PAP-Version must be 1', ['supported' => [1]]);
    }

    public static function unauthorized(string $message = 'missing or unknown agent key'): self
    {
        return new self(ErrorCode::Unauthorized, $message);
    }

    public static function revoked(): self
    {
        return new self(ErrorCode::Revoked, 'agent key has been revoked');
    }

    public static function badLeaseToken(): self
    {
        return new self(ErrorCode::BadLeaseToken, 'lease_token was never issued for this job');
    }

    public static function notFound(string $message = 'not found'): self
    {
        return new self(ErrorCode::NotFound, $message);
    }

    public static function conflict(string $message, string $status): self
    {
        return new self(ErrorCode::Conflict, $message, ['status' => $status]);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(ErrorCode::RateLimited, 'too many requests', ['retry_after' => $retryAfterSeconds]);
    }

    public static function serverError(string $message = 'internal server error'): self
    {
        return new self(ErrorCode::ServerError, $message);
    }
}
