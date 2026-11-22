<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

/**
 * What one run of FinalizeDays did. Counts only: nothing here says whose days they were.
 */
final readonly class FinalizeReport
{
    public function __construct(
        /** Days that became final. */
        public int $days,
        /** Weekly baselines that were calculated. */
        public int $baselines,
        /** Users whose next step failed: see the log. */
        public int $failedUsers,
        /** Whether the run stopped with work left, because its time was up. That is no failure. */
        public bool $outOfTime,
    ) {
    }
}
