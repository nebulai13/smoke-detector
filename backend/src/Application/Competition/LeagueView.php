<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

/**
 * A league as one user sees it (HLD §4.9 "What a user sees").
 */
final readonly class LeagueView
{
    /**
     * @param list<LeagueEntry> $entries In ranking order; those without a rank last, by name.
     */
    public function __construct(
        public CalendarPeriod $period,
        /** True once the user's own rank for the period is stored. */
        public bool $final,
        public array $entries,
        /** The user's stored rank and reward. Null until final. */
        public ?LeagueAward $award,
    ) {
    }
}
