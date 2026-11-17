<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Plan;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineCalculator;
use SmokeDetector\Domain\Plan\BaselineRules;
use SmokeDetector\Domain\Plan\BaselineSource;

/**
 * Weekly baseline update, SRS FR-PLAN-04 to FR-PLAN-06.
 *
 * Daily counts are listed oldest first. `null` means the day was not checked in.
 */
final class BaselineCalculatorTest extends TestCase
{
    private BaselineCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BaselineCalculator(new BaselineRules());
    }

    public function test_FR_PLAN_04_new_baseline_is_70_percent_week_mean_plus_30_percent_28_day_mean(): void
    {
        // W = 6. M = (21·10 + 7·6) / 28 = 9. B = 0.7·6 + 0.3·9 = 6.9
        $days = [...self::days(21, 10), ...self::days(7, 6)];

        $update = $this->calculator->weeklyUpdate(Baseline::of(10.0), $days);

        self::assertSame(6.9, $update->value->toFloat());
        self::assertSame(BaselineSource::Weekly, $update->source);
        self::assertSame(6.0, $update->weekMean);
        self::assertSame(9.0, $update->monthMean);
        self::assertSame(7, $update->weekDays);
        self::assertSame(28, $update->monthDays);
    }

    public function test_FR_PLAN_04_days_without_check_in_are_excluded_from_both_means(): void
    {
        // W = 20 / 5 = 4. M = (10·8 + 20) / 15 = 6.67. B = 2.8 + 2.0 = 4.8
        $days = [...self::days(11, null), ...self::days(10, 8), 4, 4, null, 4, null, 4, 4];

        $update = $this->calculator->weeklyUpdate(Baseline::of(6.0), $days);

        self::assertSame(4.8, $update->value->toFloat());
        self::assertSame(5, $update->weekDays);
        self::assertSame(15, $update->monthDays);
        self::assertSame(4.0, $update->weekMean);
        self::assertSame(6.67, $update->monthMean);
    }

    public function test_FR_PLAN_04_means_kept_as_inputs_of_the_update_are_rounded_to_two_decimals(): void
    {
        // W = 31 / 7 = 4.4286 → 4.43. M = (7·8 + 31) / 14 = 6.2143 → 6.21. B = 3.1 + 1.864 = 4.964 → 5.0
        $days = [...self::days(14, null), ...self::days(7, 8), 4, 4, 5, 4, 5, 4, 5];

        $update = $this->calculator->weeklyUpdate(Baseline::of(6.0), $days);

        self::assertSame(4.43, $update->weekMean);
        self::assertSame(6.21, $update->monthMean);
        self::assertSame(5.0, $update->value->toFloat());
    }

    public function test_FR_PLAN_04_means_are_reported_even_when_the_baseline_stays_unchanged(): void
    {
        // Three check-ins are too few for an update (FR-PLAN-05), but the history still shows what was seen.
        $days = [...self::days(21, 10), 2, 2, 3, null, null, null, null];

        $update = $this->calculator->weeklyUpdate(Baseline::of(8.0), $days);

        self::assertSame(BaselineSource::UnchangedLowData, $update->source);
        self::assertSame(2.33, $update->weekMean);
        self::assertSame(9.04, $update->monthMean);
        self::assertSame(24, $update->monthDays);
    }

    public function test_FR_PLAN_04_result_is_rounded_half_up_to_one_decimal(): void
    {
        // W = M = 21 / 4 = 5.25 → 5.3
        $days = [...self::days(21, null), 5, 5, 5, 6, null, null, null];

        $update = $this->calculator->weeklyUpdate(Baseline::of(5.0), $days);

        self::assertSame(5.3, $update->value->toFloat());
    }

    public function test_FR_PLAN_04_shorter_history_than_28_days_uses_the_days_that_exist(): void
    {
        $update = $this->calculator->weeklyUpdate(Baseline::of(4.0), self::days(7, 3));

        self::assertSame(3.0, $update->value->toFloat());
        self::assertSame(7, $update->monthDays);
    }

    public function test_FR_PLAN_04_more_than_28_days_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->weeklyUpdate(Baseline::of(4.0), self::days(29, 3));
    }

    public function test_FR_PLAN_04_negative_counts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->weeklyUpdate(Baseline::of(4.0), [3, 3, 3, -1, 3, 3, 3]);
    }

    public function test_FR_PLAN_05_fewer_than_4_checked_in_days_in_the_week_keeps_the_baseline(): void
    {
        $days = [...self::days(21, 10), 2, 2, 2, null, null, null, null];

        $update = $this->calculator->weeklyUpdate(Baseline::of(8.0), $days);

        self::assertSame(8.0, $update->value->toFloat());
        self::assertSame(BaselineSource::UnchangedLowData, $update->source);
        self::assertSame(3, $update->weekDays);
    }

    public function test_FR_PLAN_05_exactly_4_checked_in_days_is_enough(): void
    {
        $days = [...self::days(21, null), 2, 2, 2, 2, null, null, null];

        $update = $this->calculator->weeklyUpdate(Baseline::of(8.0), $days);

        self::assertSame(2.0, $update->value->toFloat());
        self::assertSame(BaselineSource::Weekly, $update->source);
    }

    public function test_FR_PLAN_05_no_days_at_all_keeps_the_baseline(): void
    {
        $update = $this->calculator->weeklyUpdate(Baseline::of(8.0), []);

        self::assertSame(8.0, $update->value->toFloat());
        self::assertSame(BaselineSource::UnchangedLowData, $update->source);
        self::assertNull($update->weekMean);
        self::assertNull($update->monthMean);
    }

    public function test_FR_PLAN_05_minimum_of_one_day_still_keeps_the_baseline_for_a_week_without_check_ins(): void
    {
        // The lowest minimum the rules allow is 1, so an empty week can never count as enough data.
        $calculator = new BaselineCalculator(new BaselineRules(minCheckedInWeekDays: 1));

        $empty = $calculator->weeklyUpdate(Baseline::of(8.0), [...self::days(21, 10), ...self::days(7, null)]);
        $oneDay = $calculator->weeklyUpdate(Baseline::of(8.0), [...self::days(27, null), 5]);

        self::assertSame(8.0, $empty->value->toFloat());
        self::assertSame(BaselineSource::UnchangedLowData, $empty->source);
        self::assertNull($empty->weekMean);
        self::assertSame(10.0, $empty->monthMean);
        self::assertSame(0, $empty->weekDays);
        self::assertSame(5.0, $oneDay->value->toFloat());
        self::assertSame(BaselineSource::Weekly, $oneDay->source);
    }

    #[DataProvider('tenPercentCaps')]
    public function test_FR_PLAN_06_ten_percent_cap_is_rounded_down_to_one_decimal(float $current, float $cap): void
    {
        // Four weeks of 20 a day would lift any of these baselines far above its cap.
        $update = $this->calculator->weeklyUpdate(Baseline::of($current), self::days(28, 20));

        self::assertSame($cap, $update->value->toFloat());
        self::assertSame(BaselineSource::Capped, $update->source);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function tenPercentCaps(): iterable
    {
        // Below 5.0 the half-session step is the larger cap, from 5.0 on the 10 % are.
        yield '4.9 + 0.5 (10 % would be 5.39)' => [4.9, 5.4];
        yield '5.0 × 1.1 = 5.50' => [5.0, 5.5];
        yield '5.5 × 1.1 = 6.05 → 6.0' => [5.5, 6.0];
        yield '5.9 × 1.1 = 6.49 → 6.4' => [5.9, 6.4];
        yield '6.0 × 1.1 = 6.60' => [6.0, 6.6];
        yield '7.7 × 1.1 = 8.47 → 8.4' => [7.7, 8.4];
        yield '9.9 × 1.1 = 10.89 → 10.8' => [9.9, 10.8];
    }

    public function test_FR_PLAN_06_value_exactly_at_the_cap_is_a_weekly_update_not_a_capped_one(): void
    {
        // Current 10.0 → cap 11.0. Four weeks of 11 a day give exactly 11.0: nothing was cut off.
        $atCap = $this->calculator->weeklyUpdate(Baseline::of(10.0), self::days(28, 11));
        // Current 4.5 → cap 5.0 through the half-session step. A week of 5 a day gives exactly 5.0.
        $atStepCap = $this->calculator->weeklyUpdate(Baseline::of(4.5), self::days(7, 5));

        self::assertSame(11.0, $atCap->value->toFloat());
        self::assertSame(BaselineSource::Weekly, $atCap->source);
        self::assertSame(5.0, $atStepCap->value->toFloat());
        self::assertSame(BaselineSource::Weekly, $atStepCap->source);
    }

    public function test_FR_PLAN_06_one_tenth_above_the_cap_is_capped(): void
    {
        // Current 10.0 → cap 11.0. W = 78 / 7 = 11.14, M = 111 / 10 = 11.1, B = 7.8 + 3.33 = 11.13 → 11.1
        $days = [...self::days(18, null), 11, 11, 11, 11, 11, 11, 11, 11, 11, 12];

        $update = $this->calculator->weeklyUpdate(Baseline::of(10.0), $days);

        self::assertSame(11.0, $update->value->toFloat());
        self::assertSame(BaselineSource::Capped, $update->source);
    }

    public function test_FR_PLAN_06_increase_is_capped_at_10_percent_per_week(): void
    {
        $update = $this->calculator->weeklyUpdate(Baseline::of(10.0), self::days(28, 20));

        self::assertSame(11.0, $update->value->toFloat());
        self::assertSame(BaselineSource::Capped, $update->source);
    }

    public function test_FR_PLAN_06_increase_within_the_cap_is_applied_in_full(): void
    {
        // W = 11. M = (21·10 + 7·11) / 28 = 10.25. B = 7.7 + 3.075 = 10.775 → 10.8 (cap is 11.0)
        $days = [...self::days(21, 10), ...self::days(7, 11)];

        $update = $this->calculator->weeklyUpdate(Baseline::of(10.0), $days);

        self::assertSame(10.8, $update->value->toFloat());
        self::assertSame(BaselineSource::Weekly, $update->source);
    }

    public function test_FR_PLAN_06_decrease_is_never_capped(): void
    {
        $update = $this->calculator->weeklyUpdate(Baseline::of(20.0), self::days(28, 0));

        self::assertSame(0.0, $update->value->toFloat());
        self::assertSame(BaselineSource::Weekly, $update->source);
    }

    public function test_FR_PLAN_06_low_baseline_may_always_rise_by_half_a_session(): void
    {
        // 10 % of a low baseline rounds to nothing, which would trap a relapsing quitter at 0.
        $fromZero = $this->calculator->weeklyUpdate(Baseline::of(0.0), self::days(28, 5));
        $fromTwo = $this->calculator->weeklyUpdate(Baseline::of(2.0), self::days(28, 5));

        self::assertSame(0.5, $fromZero->value->toFloat());
        self::assertSame(2.5, $fromTwo->value->toFloat());
        self::assertSame(BaselineSource::Capped, $fromZero->source);
    }

    public function test_FR_PLAN_04_highest_daily_count_that_is_accepted_is_still_exact(): void
    {
        $max = BaselineCalculator::MAX_DAILY_COUNT;

        $update = $this->calculator->weeklyUpdate(
            Baseline::fromTenths(BaselineCalculator::MAX_BASELINE_TENTHS),
            self::days(28, $max),
        );

        self::assertSame(1_000_000, $max);
        self::assertSame(10 * $max, $update->value->tenths);
        self::assertSame(BaselineSource::Weekly, $update->source);
        self::assertSame((float) $max, $update->weekMean);
    }

    public function test_FR_PLAN_04_daily_count_no_person_can_log_is_rejected(): void
    {
        // Sums of such counts would leave the integer range, and the rounding would no longer be exact.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A daily count cannot be above 1000000.');

        $this->calculator->weeklyUpdate(Baseline::of(10.0), [0, 0, 0, BaselineCalculator::MAX_DAILY_COUNT + 1]);
    }

    public function test_FR_PLAN_04_daily_count_at_the_integer_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->weeklyUpdate(Baseline::of(10.0), [PHP_INT_MAX, 0, 0, 0]);
    }

    public function test_FR_PLAN_06_current_baseline_above_anything_an_update_can_produce_is_rejected(): void
    {
        // A mean is never above the highest count, so no update produces more than 10 × the highest count.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The current baseline cannot be above 10000000 tenths.');

        $this->calculator->weeklyUpdate(
            Baseline::fromTenths(BaselineCalculator::MAX_BASELINE_TENTHS + 1),
            [1, 1, 1, 1],
        );
    }

    public function test_FR_PLAN_06_current_baseline_at_the_integer_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->weeklyUpdate(Baseline::fromTenths(PHP_INT_MAX), [1, 1, 1, 1]);
    }

    public function test_FR_PLAN_04_update_stays_exact_with_the_largest_numbers_the_rules_and_inputs_allow(): void
    {
        // Every rule number at its upper bound and every count at its upper bound: still integers.
        $max = BaselineRules::MAX_VALUE;
        $calculator = new BaselineCalculator(new BaselineRules(
            weekWeight: $max,
            monthWeight: $max,
            weekDays: $max,
            monthDays: $max,
            minCheckedInWeekDays: $max,
            maxIncreasePercent: $max,
            minIncreaseStepTenths: $max,
        ));

        $update = $calculator->weeklyUpdate(
            Baseline::fromTenths(BaselineCalculator::MAX_BASELINE_TENTHS),
            self::days($max, BaselineCalculator::MAX_DAILY_COUNT),
        );

        self::assertSame(BaselineCalculator::MAX_BASELINE_TENTHS, $update->value->tenths);
        self::assertSame($max, $update->weekDays);
        self::assertSame($max, $update->monthDays);
    }

    /**
     * @return list<int|null>
     */
    private static function days(int $howMany, ?int $count): array
    {
        return array_fill(0, $howMany, $count);
    }
}
