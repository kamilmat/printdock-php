<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Filters and paging of the job history, newest first. All filters combine with AND; null means "any".
 *
 * `states` are stored states (an `expired` job is still `queued` or `leased`, see {@see JobSummary::reportedStatus()}).
 * `createdFrom` is inclusive, `createdTo` exclusive. `text` matches, as a substring, the title (unless the job
 * is `title_sensitive`: a search must not reveal what the title hides), the job id or `reference.source_ref`;
 * case-insensitive as far as the database collation is (SQLite: ASCII only).
 * Paging is keyset: pass the previous page's {@see HistoryPage::$nextBefore} as `before`. `limit` is clamped
 * to 1..{@see MAX_LIMIT}.
 */
final class HistoryQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public readonly ?string $text;
    public readonly int $limit;

    /**
     * @param list<JobState> $states empty = every state
     */
    public function __construct(
        public readonly ?AgentId $agentId = null,
        public readonly ?string $printerId = null,
        public readonly array $states = [],
        public readonly ?\DateTimeImmutable $createdFrom = null,
        public readonly ?\DateTimeImmutable $createdTo = null,
        ?string $text = null,
        public readonly ?int $before = null,
        int $limit = self::DEFAULT_LIMIT,
    ) {
        $text = null === $text ? null : trim($text);
        $this->text = '' === $text ? null : $text;
        $this->limit = max(1, min(self::MAX_LIMIT, $limit));
    }

    /** The in-memory form of the filters; SQL adapters translate the same conditions. */
    public function matches(JobSummary $job): bool
    {
        if (null !== $this->before && $job->seq >= $this->before) {
            return false;
        }
        if (null !== $this->agentId && !$job->agentId->equals($this->agentId)) {
            return false;
        }
        if (null !== $this->printerId && $job->printerId !== $this->printerId) {
            return false;
        }
        if ([] !== $this->states && !in_array($job->state, $this->states, true)) {
            return false;
        }
        if (null !== $this->createdFrom && $job->createdAt < $this->createdFrom) {
            return false;
        }
        if (null !== $this->createdTo && $job->createdAt >= $this->createdTo) {
            return false;
        }
        if (null === $this->text) {
            return true;
        }
        $sourceRef = $job->reference['source_ref'] ?? null;
        foreach ([$job->titleSensitive ? null : $job->title, $job->id->value, is_string($sourceRef) ? $sourceRef : null] as $haystack) {
            if (null !== $haystack && false !== stripos($haystack, $this->text)) {
                return true;
            }
        }

        return false;
    }
}
