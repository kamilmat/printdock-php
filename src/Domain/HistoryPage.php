<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * One page of the job history, newest first. `nextBefore` is the cursor for the next (older) page, null on the
 * last page.
 */
final class HistoryPage
{
    /**
     * @param list<JobSummary> $jobs
     */
    public function __construct(
        public readonly array $jobs,
        public readonly ?int $nextBefore,
    ) {
    }

    /**
     * Builds a page from up to `limit + 1` candidates, newest first: the extra one only says that more exist.
     *
     * @param list<JobSummary> $candidates
     */
    public static function fromCandidates(array $candidates, int $limit): self
    {
        $jobs = array_slice($candidates, 0, $limit);
        $last = end($jobs);

        return new self($jobs, count($candidates) > $limit && false !== $last ? $last->seq : null);
    }
}
