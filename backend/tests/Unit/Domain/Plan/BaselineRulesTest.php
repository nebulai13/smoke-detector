<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Plan;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineCalculator;
use SmokeDetector\Domain\Plan\BaselineRules;

/**
 * Tunable numbers of the weekly baseline update, SRS FR-PLAN-04 to FR-PLAN-06.
 */
final class BaselineRulesTest extends TestCase
{
    public function test_FR_PLAN_04_default_weights_are_70_to_30_over_windows_of_7_and_28_days(): void
    {
        $rules = new BaselineRules();

        self::assertSame(7, $rules->weekWeight);
        self::assertSame(3, $rules->monthWeight);
        self::assertSame(7, $rules->weekDays);
        self::assertSame(28, $rules->monthDays);
    }

    public function test_FR_PLAN_05_default_minimum_is_4_checked_in_days_in_the_week(): void
    {
        self::assertSame(4, new BaselineRules()->minCheckedInWeekDays);
    }

    public function test_FR_PLAN_06_default_increase_cap_is_10_percent_or_half_a_session(): void
    {
        $rules = new BaselineRules();

        self::assertSame(10, $rules->maxIncreasePercent);
        self::assertSame(5, $rules->minIncreaseStepTenths);
    }

    public function test_FR_PLAN_04_one_of_the_two_weights_may_be_zero(): void
    {
        // Weight 0 for the 28-day mean makes the new baseline the plain mean of last week: 6.0.
        $calculator = new BaselineCalculator(new BaselineRules(weekWeight: 1, monthWeight: 0));
        $days = [...array_fill(0, 21, 10), ...array_fill(0, 7, 6)];

        self::assertSame(6.0, $calculator->weeklyUpdate(Baseline::of(10.0), $days)->value->toFloat());
    }

    public function test_FR_PLAN_04_week_weight_may_be_zero_while_the_month_weight_is_not(): void
    {
        // Weight 0 for the week mean makes the new baseline the plain 28-day mean: (21·10 + 7·6) / 28 = 9.0.
        $calculator = new BaselineCalculator(new BaselineRules(weekWeight: 0, monthWeight: 1));
        $days = [...array_fill(0, 21, 10), ...array_fill(0, 7, 6)];

        self::assertSame(9.0, $calculator->weeklyUpdate(Baseline::of(10.0), $days)->value->toFloat());
    }

    public function test_FR_PLAN_04_month_weight_may_be_larger_than_the_week_weight(): void
    {
        // 0.3 × 6 + 0.7 × 9 = 8.1
        $calculator = new BaselineCalculator(new BaselineRules(weekWeight: 3, monthWeight: 7));
        $days = [...array_fill(0, 21, 10), ...array_fill(0, 7, 6)];

        self::assertSame(8.1, $calculator->weeklyUpdate(Baseline::of(10.0), $days)->value->toFloat());
    }

    public function test_FR_PLAN_04_windows_of_a_single_day_are_the_smallest_allowed(): void
    {
        $rules = new BaselineRules(weekDays: 1, monthDays: 1, minCheckedInWeekDays: 1);

        self::assertSame(1, $rules->weekDays);
        self::assertSame(1, $rules->monthDays);
        self::assertSame(1, $rules->minCheckedInWeekDays);
    }

    public function test_FR_PLAN_05_minimum_may_be_a_single_checked_in_day(): void
    {
        self::assertSame(1, new BaselineRules(minCheckedInWeekDays: 1)->minCheckedInWeekDays);
    }

    public function test_FR_PLAN_04_week_window_of_zero_days_is_named_as_the_problem(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The week window of the baseline must be at least 1 day.');

        new BaselineRules(weekDays: 0);
    }

    /**
     * @param array<string, int> $override
     */
    #[DataProvider('largestValues')]
    public function test_FR_PLAN_04_every_number_may_be_as_large_as_the_documented_maximum(array $override): void
    {
        $rules = new BaselineRules(...$override);

        foreach ($override as $name => $value) {
            self::assertSame($value, $rules->{$name});
        }
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function largestValues(): iterable
    {
        $max = BaselineRules::MAX_VALUE;

        yield 'week weight' => [['weekWeight' => $max]];
        yield 'month weight' => [['monthWeight' => $max]];
        yield 'windows' => [['weekDays' => $max, 'monthDays' => $max]];
        yield 'month window' => [['monthDays' => $max]];
        yield 'increase percent' => [['maxIncreasePercent' => $max]];
        yield 'increase step' => [['minIncreaseStepTenths' => $max]];
    }

    /**
     * Above the maximum the weekly update could leave the integer range and stop being exact.
     *
     * @param array<string, int> $override
     */
    #[DataProvider('tooLargeValues')]
    public function test_FR_PLAN_04_numbers_above_the_documented_maximum_are_rejected(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No number of the baseline rules can be above 1000.');

        new BaselineRules(...$override);
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function tooLargeValues(): iterable
    {
        $tooLarge = BaselineRules::MAX_VALUE + 1;

        yield 'week weight' => [['weekWeight' => $tooLarge]];
        yield 'month weight' => [['monthWeight' => $tooLarge]];
        yield 'month window' => [['monthDays' => $tooLarge]];
        yield 'increase percent' => [['maxIncreasePercent' => $tooLarge]];
        yield 'increase step' => [['minIncreaseStepTenths' => $tooLarge]];
        yield 'week weight at the integer limit' => [['weekWeight' => PHP_INT_MAX]];
        yield 'month weight at the integer limit' => [['monthWeight' => PHP_INT_MAX]];
        yield 'increase percent at the integer limit' => [['maxIncreasePercent' => PHP_INT_MAX]];
    }

    public function test_FR_PLAN_04_week_window_above_the_documented_maximum_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaselineRules(weekDays: BaselineRules::MAX_VALUE + 1, monthDays: BaselineRules::MAX_VALUE + 1);
    }

    public function test_FR_PLAN_04_month_window_may_equal_the_week_window(): void
    {
        self::assertSame(7, new BaselineRules(weekDays: 7, monthDays: 7)->monthDays);
    }

    public function test_FR_PLAN_05_minimum_may_be_every_day_of_the_week(): void
    {
        self::assertSame(7, new BaselineRules(minCheckedInWeekDays: 7)->minCheckedInWeekDays);
    }

    public function test_FR_PLAN_06_increase_cap_may_be_switched_to_zero(): void
    {
        $rules = new BaselineRules(maxIncreasePercent: 0, minIncreaseStepTenths: 0);

        self::assertSame(0, $rules->maxIncreasePercent);
        self::assertSame(0, $rules->minIncreaseStepTenths);
    }

    /**
     * @param array<string, int> $override
     */
    #[DataProvider('invalidRules')]
    public function test_FR_PLAN_04_nonsensical_rules_are_rejected(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaselineRules(...$override);
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function invalidRules(): iterable
    {
        yield 'negative week weight' => [['weekWeight' => -7]];
        yield 'negative month weight' => [['monthWeight' => -3]];
        // The weights are divided by their sum, so they cannot both be zero.
        yield 'both weights zero' => [['weekWeight' => 0, 'monthWeight' => 0]];
        yield 'week window of zero days' => [['weekDays' => 0, 'minCheckedInWeekDays' => 0]];
        yield 'negative week window' => [['weekDays' => -7]];
        yield 'month window shorter than week window' => [['weekDays' => 7, 'monthDays' => 6]];
        // With a minimum of zero an empty week would count as "enough data".
        yield 'minimum of zero checked-in days' => [['minCheckedInWeekDays' => 0]];
        // More days than the week has can never be reached, so the baseline would be frozen forever.
        yield 'minimum above the week window' => [['minCheckedInWeekDays' => 8]];
        yield 'negative increase cap' => [['maxIncreasePercent' => -10]];
        yield 'negative minimum increase step' => [['minIncreaseStepTenths' => -5]];
    }
}
