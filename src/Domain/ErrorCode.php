<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * The error catalogue of PAP/1 (section 12): the exact `error` strings and the HTTP status each one travels with.
 */
enum ErrorCode: string
{
    case BadRequest = 'bad_request';
    case UnsupportedVersion = 'unsupported_version';
    case Unauthorized = 'unauthorized';
    case Revoked = 'revoked';
    case BadLeaseToken = 'bad_lease_token';
    case NotFound = 'not_found';
    case Conflict = 'conflict';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';

    public function httpStatus(): int
    {
        return match ($this) {
            self::BadRequest, self::UnsupportedVersion => 400,
            self::Unauthorized, self::Revoked => 401,
            self::BadLeaseToken => 403,
            self::NotFound => 404,
            self::Conflict => 409,
            self::RateLimited => 429,
            self::ServerError => 500,
        };
    }
}
