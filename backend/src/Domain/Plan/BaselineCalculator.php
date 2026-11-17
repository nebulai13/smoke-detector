<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Plan;

use InvalidArgumentException;

/**
 * Weekly baseline update (SRS FR-PLAN-04 to FR-PLAN-06).
 *
 * new = round1(0.7 × mean of last week + 0.3 × mean of last 28 days), over checked-in days only.
 */
final readonly class BaselineCalculator
{
    /**
     * More than anyone can log in a day (one session every 0.09 seconds). With counts up to here and
     * rule numbers up to BaselineRules::MAX_VALUE, every sum and product of the update stays far
     * inside a 64-bit integer (below 10^17), so the rounding is exact.
     */
    public const int MAX_DAILY_COUNT = 1_000_000;

    /** A mean is never above the highest count, so no update produces a baseline above this. */
    public const int MAX_BASELINE_TENTHS = 10 * self::MAX_DAILY_COUNT;

    public function __construct(private BaselineRules $rules)
    {
    }

    /**
     * @param list<int|null> $dailyCounts Sessions per day for up to the last 28 days, oldest first.
     *                                    Null means the day was not checked in.
     */
    public function weeklyUpdate(Baseline $current, array $dailyCounts): BaselineUpdate
    {
        if (count($dailyCounts) > $this->rules->monthDays) {
            throw new InvalidArgumentException("At most {$this->rules->monthDays} days are allowed.");
        }
        // Above this the cap below could leave the integer range and silently turn into a float.
        if ($current->tenths > self::MAX_BASELINE_TENTHS) {
            throw new InvalidArgumentException(
                'The current baseline cannot be above ' . self::MAX_BASELINE_TENTHS . ' tenths.',
            );
        }

        $month = self::checkedIn($dailyCounts);
        $week = self::checkedIn(array_slice($dailyCounts, -$this->rules->weekDays));
        $weekDays = count($week);
        $monthDays = count($month);
        $weekSum = array_sum($week);
        $monthSum = array_sum($month);
        $weekMean = $weekDays > 0 ? round($weekSum / $weekDays, 2) : null;
        $monthMean = $monthDays > 0 ? round($monthSum / $monthDays, 2) : null;

        // The minimum is at least 1 (BaselineRules), so a week without check-ins always ends here
        // and the division below never sees an empty week.
        if ($weekDays < $this->rules->minCheckedInWeekDays) {
            return new BaselineUpdate(
                $current,
                BaselineSource::UnchangedLowData,
                $weekMean,
                $monthMean,
                $weekDays,
                $monthDays,
            );
        }

        // Integer arithmetic: tenths = 10 × (ww × W + mw × M) / (ww + mw), rounded half up.
        $numerator = 10 * ($this->rules->weekWeight * $weekSum * $monthDays
            + $this->rules->monthWeight * $monthSum * $weekDays);
        $denominator = ($this->rules->weekWeight + $this->rules->monthWeight) * $weekDays * $monthDays;
        $tenths = intdiv(2 * $numerator + $denominator, 2 * $denominator);

        $cap = max(
            intdiv($current->tenths * (100 + $this->rules->maxIncreasePercent), 100),
            $current->tenths + $this->rules->minIncreaseStepTenths,
        );
        $capped = $tenths > $cap;

        return new BaselineUpdate(
            Baseline::fromTenths($capped ? $cap : $tenths),
            $capped ? BaselineSource::Capped : BaselineSource::Weekly,
            $weekMean,
            $monthMean,
            $weekDays,
            $monthDays,
        );
    }

    /**
     * @param list<int|null> $dailyCounts
     *
     * @return list<int>
     */
    private static function checkedIn(array $dailyCounts): array
    {
        $counts = [];
        foreach ($dailyCounts as $count) {
            if ($count === null) {
                continue;
            }
            if ($count < 0) {
                throw new InvalidArgumentException('A daily count cannot be negative.');
            }
            if ($count > self::MAX_DAILY_COUNT) {
                throw new InvalidArgumentException('A daily count cannot be above ' . self::MAX_DAILY_COUNT . '.');
            }
            $counts[] = $count;
        }

        return $counts;
    }
}
