<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * How the agent reaches a printer (section 4.3). `Ipp` talks to a network printer directly over IPP, without a system
 * driver (section 9.5); send it only to agents whose `capabilities.transports` lists `ipp`.
 */
enum PrinterTransport: string
{
    case Socket = 'socket';
    case Spooler = 'spooler';
    case Cups = 'cups';
    case Ipp = 'ipp';
}
