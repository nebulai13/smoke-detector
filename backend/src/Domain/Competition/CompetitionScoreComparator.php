<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Competition;

/**
 * Winner order of SRS FR-COMP-03: higher reduction, then more smoke-free days, then more check-ins.
 *
 * Kept apart from CompetitionScore because the SRS defines these tie-breakers for duels only.
 */
final readonly class CompetitionScoreComparator
{
    /**
     * @return int 1 when $a beats $b, -1 when $b beats $a, 0 for a draw
     */
    public function compare(CompetitionScore $a, CompetitionScore $b): int
    {
        // Each level is consulted only when all levels before it are exactly equal.
        return $a->reduction->compareTo($b->reduction)
            ?: $a->smokeFreeDays <=> $b->smokeFreeDays
            ?: $a->checkIns <=> $b->checkIns;
    }
}
