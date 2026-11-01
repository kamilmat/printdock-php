<?php

declare(strict_types=1);

namespace PrintDock\Server\Application;

/**
 * Deployment facts the protocol core cannot know.
 *
 * `$contentBaseUrl` is the absolute PAP base URL as agents see it (for example
 * `https://wms.example.com/api/printing/agent/v1`). It is needed for `content_url` delivery, which must stay on
 * the origin of the agent's `server_url`; without it the server announces `content_url: false` and always
 * sends content inline.
 *
 * `$longPollMaxS` turns on long polling (5.4.5): a claim with `wait_s` is held up to that many seconds until a
 * job arrives. Off (0) by default, because a waiting station holds one PHP worker (and one database connection)
 * for the whole wait: a PHP-FPM pool of 10 workers with 8 waiting stations has 2 left for everything else. Turn
 * it on only with workers to spare, `max_execution_time` above the limit, and no transaction wrapped around the
 * PAP request (the wait polls the database every {@see PapServer::LONG_POLL_STEP_US}, holding no locks between).
 *
 * `$claimCooldown` (on by default) answers 429 to a station that claims again too soon after an empty claim
 * ({@see PapServer::claim()}). Turn it off only for test tools that claim back to back on purpose.
 */
final class PapServerConfig
{
    public const LONG_POLL_LIMIT_S = 60;

    public function __construct(
        public readonly ?string $contentBaseUrl = null,
        public readonly int $longPollMaxS = 0,
        public readonly bool $claimCooldown = true,
    ) {
        if ($longPollMaxS < 0 || $longPollMaxS > self::LONG_POLL_LIMIT_S) {
            throw new \InvalidArgumentException(sprintf('longPollMaxS must be between 0 and %d', self::LONG_POLL_LIMIT_S));
        }
    }

    public function contentUrlFor(string $jobId): ?string
    {
        if (null === $this->contentBaseUrl) {
            return null;
        }

        return rtrim($this->contentBaseUrl, '/') . '/jobs/' . rawurlencode($jobId) . '/content';
    }
}
