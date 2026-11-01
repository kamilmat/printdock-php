<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Who owns a station's printer configuration (section 4.3): the server, or the agent's local file.
 */
enum ManagedBy: string
{
    case Server = 'server';
    case Agent = 'agent';
}
