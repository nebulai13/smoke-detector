<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Plan;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\PlanRules;
use SmokeDetector\Domain\Plan\ReductionPlan;
use SmokeDetector\Domain\Plan\TaperSpeed;

/**
 * Daily limit, SRS FR-PLAN-01: floor(baseline × factor), factor 0.9 / 0.8 / 0.7.
 */
final class ReductionPlanTest extends TestCase
{
    #[DataProvider('limits')]
    public function test_FR_PLAN_01_daily_limit_is_baseline_times_taper_factor_rounded_down(
        float $baseline,
        TaperSpeed $speed,
        int $expected,
    ): void {
        self::assertSame(
            $expected,
            ReductionPlan::dailyLimit(Baseline::of($baseline), $speed, Goal::quit(), new PlanRules()),
        );
    }

    /**
     * @return iterable<string, array{float, TaperSpeed, int}>
     */
    public static function limits(): iterable
    {
        yield '10.0 gentle' => [10.0, TaperSpeed::Gentle, 9];
        yield '10.0 normal' => [10.0, TaperSpeed::Normal, 8];
        yield '10.0 fast' => [10.0, TaperSpeed::Fast, 7];
        yield '7.4 normal = 5.92' => [7.4, TaperSpeed::Normal, 5];
        yield '5.0 gentle = 4.5' => [5.0, TaperSpeed::Gentle, 4];
        yield '3.0 fast = 2.1' => [3.0, TaperSpeed::Fast, 2];
        yield '80.0 gentle' => [80.0, TaperSpeed::Gentle, 72];
        // Just below a whole number: a limit that is rounded, or floored one step off, gives 10.
        yield '11.1 gentle = 9.99' => [11.1, TaperSpeed::Gentle, 9];
        yield '12.4 normal = 9.92' => [12.4, TaperSpeed::Normal, 9];
        yield '14.2 fast = 9.94' => [14.2, TaperSpeed::Fast, 9];
        yield '79.9 gentle = 71.91' => [79.9, TaperSpeed::Gentle, 71];
    }

    public function test_FR_PLAN_01_daily_limit_is_the_exact_floor_for_every_baseline_and_speed(): void
    {
        // Oracle in whole numbers: the limit L is the one integer with 1000 × L ≤ tenths × percent < 1000 × (L + 1).
        $rules = new PlanRules();
        $percentages = [90 => TaperSpeed::Gentle, 80 => TaperSpeed::Normal, 70 => TaperSpeed::Fast];

        for ($tenths = 0; $tenths <= 800; $tenths++) {
            foreach ($percentages as $percent => $speed) {
                $limit = ReductionPlan::dailyLimit(Baseline::fromTenths($tenths), $speed, Goal::quit(), $rules);

                self::assertLessThanOrEqual($tenths * $percent, 1000 * $limit, "{$tenths} tenths, {$percent} %");
                self::assertGreaterThan($tenths * $percent, 1000 * ($limit + 1), "{$tenths} tenths, {$percent} %");
            }
        }
    }

    #[DataProvider('quitBoundaries')]
    public function test_FR_PLAN_01_quit_mode_reaches_zero_exactly_when_the_baseline_falls_below_1_over_f(
        float $baseline,
        TaperSpeed $speed,
        int $expected,
    ): void {
        self::assertSame(
            $expected,
            ReductionPlan::dailyLimit(Baseline::of($baseline), $speed, Goal::quit(), new PlanRules()),
        );
    }

    /**
     * @return iterable<string, array{float, TaperSpeed, int}>
     */
    public static function quitBoundaries(): iterable
    {
        // 1 / 0.9 = 1.11, 1 / 0.8 = 1.25, 1 / 0.7 = 1.43
        yield 'gentle 1.1 = 0.99' => [1.1, TaperSpeed::Gentle, 0];
        yield 'gentle 1.2 = 1.08' => [1.2, TaperSpeed::Gentle, 1];
        yield 'normal 1.2 = 0.96' => [1.2, TaperSpeed::Normal, 0];
        yield 'normal 1.3 = 1.04' => [1.3, TaperSpeed::Normal, 1];
        yield 'fast 1.4 = 0.98' => [1.4, TaperSpeed::Fast, 0];
        yield 'fast 1.5 = 1.05' => [1.5, TaperSpeed::Fast, 1];
    }

    public function test_FR_PLAN_01_quit_mode_reaches_zero_once_baseline_is_low_enough(): void
    {
        $rules = new PlanRules();

        // 1.3 × 0.8 = 1.04 → 1, but 1.2 × 0.8 = 0.96 → 0
        self::assertSame(1, ReductionPlan::dailyLimit(Baseline::of(1.3), TaperSpeed::Normal, Goal::quit(), $rules));
        self::assertSame(0, ReductionPlan::dailyLimit(Baseline::of(1.2), TaperSpeed::Normal, Goal::quit(), $rules));
        self::assertSame(0, ReductionPlan::dailyLimit(Baseline::of(0.0), TaperSpeed::Gentle, Goal::quit(), $rules));
    }

    public function test_FR_PLAN_01_reduce_mode_never_goes_below_the_target(): void
    {
        $goal = Goal::reduceTo(5);
        $rules = new PlanRules();

        self::assertSame(8, ReductionPlan::dailyLimit(Baseline::of(10.0), TaperSpeed::Normal, $goal, $rules));
        self::assertSame(5, ReductionPlan::dailyLimit(Baseline::of(5.0), TaperSpeed::Normal, $goal, $rules));
        self::assertSame(5, ReductionPlan::dailyLimit(Baseline::of(2.0), TaperSpeed::Fast, $goal, $rules));
    }

    public function test_FR_PLAN_01_taper_factors_come_from_the_rules_not_from_the_code(): void
    {
        $rules = new PlanRules(gentleLimitPercent: 95, normalLimitPercent: 50, fastLimitPercent: 25);
        $baseline = Baseline::of(10.0);

        // 10.0 × 0.95 = 9.5 → 9, 10.0 × 0.5 = 5, 10.0 × 0.25 = 2.5 → 2
        self::assertSame(9, ReductionPlan::dailyLimit($baseline, TaperSpeed::Gentle, Goal::quit(), $rules));
        self::assertSame(5, ReductionPlan::dailyLimit($baseline, TaperSpeed::Normal, Goal::quit(), $rules));
        self::assertSame(2, ReductionPlan::dailyLimit($baseline, TaperSpeed::Fast, Goal::quit(), $rules));
    }

    public function test_FR_PLAN_01_a_factor_of_100_percent_keeps_the_limit_at_the_whole_part_of_the_baseline(): void
    {
        $rules = new PlanRules(gentleLimitPercent: 100, normalLimitPercent: 80, fastLimitPercent: 70);

        self::assertSame(7, ReductionPlan::dailyLimit(Baseline::of(7.9), TaperSpeed::Gentle, Goal::quit(), $rules));
    }

    public function test_FR_PLAN_01_quit_goal_has_a_target_of_zero(): void
    {
        self::assertSame(0, Goal::quit()->target);
        self::assertTrue(Goal::quit()->isQuit());
    }

    public function test_FR_PLAN_01_smallest_reduce_target_is_one_session_a_day(): void
    {
        $goal = Goal::reduceTo(1);

        self::assertSame(1, $goal->target);
        self::assertFalse($goal->isQuit());
        // 1.0 × 0.8 = 0.8 → 0, but the limit stays at the target.
        self::assertSame(1, ReductionPlan::dailyLimit(Baseline::of(1.0), TaperSpeed::Normal, $goal, new PlanRules()));
    }

    public function test_FR_PLAN_01_reduce_goal_keeps_its_target(): void
    {
        self::assertSame(5, Goal::reduceTo(5)->target);
        self::assertFalse(Goal::reduceTo(5)->isQuit());
    }

    public function test_FR_PLAN_01_negative_reduce_target_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Goal::reduceTo(-1);
    }

    public function test_FR_PLAN_01_largest_baseline_a_limit_can_be_derived_from_is_still_exact(): void
    {
        // tenths × 100 is the largest product that still fits into an integer.
        $tenths = intdiv(PHP_INT_MAX, 100);
        $rules = new PlanRules(gentleLimitPercent: 100);

        self::assertSame(
            intdiv($tenths, 10),
            ReductionPlan::dailyLimit(Baseline::fromTenths($tenths), TaperSpeed::Gentle, Goal::quit(), $rules),
        );
    }

    #[DataProvider('absurdBaselines')]
    public function test_FR_PLAN_01_baseline_too_large_for_an_exact_limit_is_rejected(int $tenths): void
    {
        // The product would silently turn into a float, and the floor would no longer be exact.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The baseline is too large to derive a daily limit from.');

        ReductionPlan::dailyLimit(
            Baseline::fromTenths($tenths),
            TaperSpeed::Gentle,
            Goal::quit(),
            new PlanRules(gentleLimitPercent: 100),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function absurdBaselines(): iterable
    {
        yield 'one tenth above the largest exact product' => [intdiv(PHP_INT_MAX, 100) + 1];
        yield 'largest integer' => [PHP_INT_MAX];
    }

    public function test_FR_PLAN_01_reduce_target_must_be_above_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Goal::reduceTo(0);
    }

    public function test_FR_PLAN_01_baseline_cannot_be_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Baseline::of(-0.1);
    }
}
