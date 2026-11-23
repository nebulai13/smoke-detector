<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

/**
 * What one run of RankLeagues did. Counts only: nothing here says whose results they were.
 */
final readonly class LeagueReport
{
    public function __construct(
        /** Results that got their rank. */
        public int $ranked,
        /** Results that wait for a friend's result, to be looked at again later. */
        public int $waiting,
        /** Results that failed, were rolled back and logged. */
        public int $failed,
    ) {
    }
}
