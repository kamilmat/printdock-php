<?php

declare(strict_types=1);

namespace PrintDock\Server\Port;

use PrintDock\Server\Domain\Release;

/**
 * Optional update channel for `GET /release` (section 8). Without one the server answers 204 to everyone.
 */
interface ReleaseProvider
{
    /** The release to offer to this agent build, or null when it should not update. */
    public function releaseFor(string $os, string $arch, string $currentVersion): ?Release;
}
