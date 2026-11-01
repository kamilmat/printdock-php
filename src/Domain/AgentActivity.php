<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Kinds of request that mark an agent as alive. A paused agent sends `hello` and acks but no `claim`, so the
 * kinds are kept apart: missing claims alone are not a fault (section 4.1).
 */
enum AgentActivity: string
{
    case Claim = 'claim';
    case Other = 'other';
}
