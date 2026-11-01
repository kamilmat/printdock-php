<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Job content formats (section 9.1). `png` is reserved: a server sends it only to an agent that listed it.
 */
enum JobFormat: string
{
    case Zpl = 'zpl';
    case Epl = 'epl';
    case Raw = 'raw';
    case Pdf = 'pdf';
    case Png = 'png';

    public function contentType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Png => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
