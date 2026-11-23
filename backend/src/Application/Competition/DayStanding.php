<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

/**
 * One day of a duel in the detail (FR-COMP-09): the reduction of each side on that day.
 */
final readonly class DayStanding
{
    public function __construct(
        /** The day of the duel, from 1: the two sides can have different dates for it. */
        public int $day,
        /** Percent with one decimal. Null while the day does not count yet. */
        public ?float $mine,
        /** As $mine, and null whenever the opponent is not shown. */
        public ?float $theirs,
    ) {
    }
}
