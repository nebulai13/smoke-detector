<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use SmokeDetector\Application\Day\Calendar;
use SmokeDetector\Domain\Competition\CompetitionScore;
use SmokeDetector\Domain\Competition\CompetitionScoreCalculator;
use SmokeDetector\Domain\Competition\FrozenBaseline;
use SmokeDetector\Domain\Day\DayOutcome;

/**
 * The days of one side of a duel that count, scored against the side's frozen baseline (HLD §4.8
 * "Score"). Built by DuelSides.
 */
final readonly class SideDays
{
    /**
     * @param list<DayOutcome> $counted The days that count, in order: always the first days of the
     *                                  duel, so the one at index 0 is day 1.
     */
    public function __construct(
        /** The calendar of the side's user, as the days were read with it. */
        public Calendar $calendar,
        public FrozenBaseline $baseline,
        private array $counted,
    ) {
    }

    /**
     * @return CompetitionScore|null Null when no day counts: the side is not rated (yet).
     */
    public function score(): ?CompetitionScore
    {
        return $this->counted === [] ? null : (new CompetitionScoreCalculator())->score($this->baseline, $this->counted);
    }

    /**
     * @return list<DayLevel> The level of each day that counts, in order: the one at index 0 is day 1.
     */
    public function levels(): array
    {
        return array_map(fn (DayOutcome $outcome): DayLevel => DayLevel::of($outcome, $this->baseline), $this->counted);
    }
}
