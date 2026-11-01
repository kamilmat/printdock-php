<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * How the agent reaches a printer (section 4.3).
 */
enum PrinterTransport: string
{
    case Socket = 'socket';
    case Spooler = 'spooler';
    case Cups = 'cups';
}
