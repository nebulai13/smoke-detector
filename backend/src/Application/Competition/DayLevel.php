<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use SmokeDetector\Domain\Competition\FrozenBaseline;
use SmokeDetector\Domain\Day\DayOutcome;

/**
 * How one day of a duel went, as both of the two see it (FR-COMP-09, FR-FRND-03, HLD §4.8 "What the
 * opponent sees"): one of four levels and never a number, because an exact figure per day could be
 * calculated back to the baseline and the counts.
 */
enum DayLevel: string
{
    /** Checked in with a count of 0. */
    case SmokeFree = 'smoke_free';
    /** Checked in with at most half of the frozen baseline. */
    case WellUnder = 'well_under';
    /** Checked in below the frozen baseline. */
    case Under = 'under';
    /** At or over the frozen baseline, or not checked in. */
    case NotUnder = 'not_under';

    /**
     * The level of a day against the side's frozen baseline, compared exactly in tenths.
     */
    public static function of(DayOutcome $outcome, FrozenBaseline $baseline): self
    {
        if (!$outcome->checkedIn) {
            return self::NotUnder;
        }
        $countTenths = $outcome->count * 10;

        return match (true) {
            $outcome->count === 0 => self::SmokeFree,
            $countTenths * 2 <= $baseline->tenths => self::WellUnder,
            $countTenths < $baseline->tenths => self::Under,
            default => self::NotUnder,
        };
    }
}
