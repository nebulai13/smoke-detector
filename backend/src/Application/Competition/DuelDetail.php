<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

/**
 * POST /duels/show: the duel as in the list, and its days (FR-COMP-09).
 */
final readonly class DuelDetail
{
    /**
     * @param list<DayStanding> $days One per day of a running or completed duel, in order. Empty
     *                                for every other duel.
     */
    public function __construct(
        public DuelView $duel,
        public array $days,
    ) {
    }
}
