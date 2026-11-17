<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Plan;

use InvalidArgumentException;

/**
 * Tunable numbers of the weekly baseline update (SRS FR-PLAN-04 to FR-PLAN-06).
 */
final readonly class BaselineRules
{
    /**
     * Upper bound of every number here. Together with the input bounds of BaselineCalculator it keeps
     * the weekly update inside 64-bit integers, and it is far above any value that makes sense for
     * the product (weights are shares, the windows are days, the cap is a percentage).
     */
    public const int MAX_VALUE = 1000;

    public function __construct(
        /** Weight of the last-week mean, relative to $monthWeight. */
        public int $weekWeight = 7,
        /** Weight of the 28-day mean. */
        public int $monthWeight = 3,
        public int $weekDays = 7,
        public int $monthDays = 28,
        /** Below this many checked-in days in the last week, the baseline stays unchanged. */
        public int $minCheckedInWeekDays = 4,
        /** The baseline may rise by at most this share per week. */
        public int $maxIncreasePercent = 10,
        /** The baseline may always rise by this much (in tenths), so low baselines are not stuck. */
        public int $minIncreaseStepTenths = 5,
    ) {
        // The week window and the minimum of checked-in days are bounded through the month window below.
        if (max($weekWeight, $monthWeight, $monthDays, $maxIncreasePercent, $minIncreaseStepTenths) > self::MAX_VALUE) {
            throw new InvalidArgumentException(
                'No number of the baseline rules can be above ' . self::MAX_VALUE . '.',
            );
        }
        // The weights are divided by their sum. One of them may be zero, which drops that mean.
        if ($weekWeight < 0 || $monthWeight < 0 || $weekWeight + $monthWeight < 1) {
            throw new InvalidArgumentException(
                'The baseline weights cannot be negative and at least one must be above zero.',
            );
        }
        if ($weekDays < 1) {
            throw new InvalidArgumentException('The week window of the baseline must be at least 1 day.');
        }
        // The week is the last part of the month window, so a shorter month would cut days out of the week.
        if ($monthDays < $weekDays) {
            throw new InvalidArgumentException(
                'The month window of the baseline cannot be shorter than the week window.',
            );
        }
        // Zero would accept an empty week as enough data, and more than the week has could never be reached.
        if ($minCheckedInWeekDays < 1 || $minCheckedInWeekDays > $weekDays) {
            throw new InvalidArgumentException(
                'The minimum of checked-in days must be between 1 and the length of the week window.',
            );
        }
        if ($maxIncreasePercent < 0 || $minIncreaseStepTenths < 0) {
            throw new InvalidArgumentException('The weekly increase cap of the baseline cannot be negative.');
        }
    }
}
