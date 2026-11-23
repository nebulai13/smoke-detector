<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;

/**
 * The rank of a stored league result, as it was decided among the friends of that moment (HLD §4.9
 * "Rank and reward"). Never recalculated.
 */
final readonly class LeagueAward
{
    public function __construct(
        /** 1 is the best. Equal results share a rank. */
        public int $rank,
        /** The user and the friends who had a result. */
        public int $participants,
        /** What the rank earned. 0 when the rule table pays nothing. */
        public int $xp,
        public DateTimeImmutable $rankedAt,
    ) {
    }
}
