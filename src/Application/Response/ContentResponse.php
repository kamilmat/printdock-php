<?php

declare(strict_types=1);

namespace PrintDock\Server\Application\Response;

/**
 * `content` 200: the raw bytes of a leased or spooled job (section 7).
 */
final class ContentResponse
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $contentType,
    ) {
    }
}
