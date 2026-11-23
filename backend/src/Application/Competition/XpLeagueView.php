<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

/**
 * The weekly XP ranking of a user and their friends (FR-COMP-07, HLD §4.9 "Weekly XP ranking").
 */
final readonly class XpLeagueView
{
    /**
     * @param list<XpLeagueEntry> $entries Most XP first, then by name.
     */
    public function __construct(
        /** The viewer's current ISO week. */
        public CalendarPeriod $period,
        public array $entries,
    ) {
    }
}
