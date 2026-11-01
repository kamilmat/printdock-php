<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * A refused administrative operation (creating agents, enqueueing jobs). Not part of PAP, so it is not in the
 * error catalogue; the host maps `$kind` onto whatever its own admin surface speaks.
 */
final class AdminException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const BAD_REQUEST = 'bad_request';
    public const CONFLICT = 'conflict';
    public const NOT_SUPPORTED = 'not_supported';
    public const AGENT_NOT_REVOKED = 'agent_not_revoked';
    public const AGENT_HAS_OPEN_JOBS = 'agent_has_open_jobs';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
