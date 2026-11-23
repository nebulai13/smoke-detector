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
     * @param list<DayOutcome>  $counted  The days that count, in order.
     * @param array<int, float> $percents The reduction of each day that counts, in percent, by the
     *                                    number of the day in the duel (from 1).
     */
    public function __construct(
        /** The calendar of the side's user, as the days were read with it. */
        public Calendar $calendar,
        public FrozenBaseline $baseline,
        private array $counted,
        public array $percents,
    ) {
    }

    /**
     * @return CompetitionScore|null Null when no day counts: the side is not rated (yet).
     */
    public function score(): ?CompetitionScore
    {
        return $this->counted === [] ? null : (new CompetitionScoreCalculator())->score($this->baseline, $this->counted);
    }
}
