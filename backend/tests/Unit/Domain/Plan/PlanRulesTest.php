<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Plan;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Plan\PlanRules;
use SmokeDetector\Domain\Plan\TaperSpeed;

/**
 * Tunable numbers of the daily limit, SRS FR-PLAN-01: f = 0.9 (gentle) / 0.8 (normal) / 0.7 (fast).
 */
final class PlanRulesTest extends TestCase
{
    public function test_FR_PLAN_01_default_taper_factors_are_90_80_and_70_percent(): void
    {
        $rules = new PlanRules();

        self::assertSame(90, $rules->gentleLimitPercent);
        self::assertSame(80, $rules->normalLimitPercent);
        self::assertSame(70, $rules->fastLimitPercent);
    }

    public function test_FR_PLAN_01_each_taper_speed_maps_to_its_own_percentage(): void
    {
        $rules = new PlanRules(gentleLimitPercent: 95, normalLimitPercent: 85, fastLimitPercent: 60);

        self::assertSame(95, $rules->limitPercent(TaperSpeed::Gentle));
        self::assertSame(85, $rules->limitPercent(TaperSpeed::Normal));
        self::assertSame(60, $rules->limitPercent(TaperSpeed::Fast));
    }

    public function test_FR_PLAN_01_every_taper_speed_has_a_percentage(): void
    {
        $rules = new PlanRules();

        foreach (TaperSpeed::cases() as $speed) {
            self::assertGreaterThanOrEqual(1, $rules->limitPercent($speed));
            self::assertLessThanOrEqual(100, $rules->limitPercent($speed));
        }
    }

    public function test_FR_PLAN_01_the_edges_1_and_100_percent_are_allowed(): void
    {
        $rules = new PlanRules(gentleLimitPercent: 100, normalLimitPercent: 100, fastLimitPercent: 1);

        self::assertSame(100, $rules->limitPercent(TaperSpeed::Gentle));
        self::assertSame(1, $rules->limitPercent(TaperSpeed::Fast));
    }

    public function test_FR_PLAN_01_equal_percentages_for_two_speeds_are_allowed(): void
    {
        $rules = new PlanRules(gentleLimitPercent: 80, normalLimitPercent: 80, fastLimitPercent: 80);

        self::assertSame(80, $rules->limitPercent(TaperSpeed::Fast));
    }

    #[DataProvider('invalidPercentages')]
    public function test_FR_PLAN_01_invalid_percentages_are_rejected(int $gentle, int $normal, int $fast): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlanRules($gentle, $normal, $fast);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalidPercentages(): iterable
    {
        yield 'gentle of zero' => [0, 0, 0];
        yield 'fast of zero' => [90, 80, 0];
        yield 'negative fast' => [90, 80, -70];
        yield 'gentle above 100' => [101, 80, 70];
        yield 'normal above 100' => [100, 101, 70];
        yield 'fast above 100' => [100, 100, 101];
        // A "fast" plan that allows more than a "gentle" one would mislabel the choice made in onboarding.
        yield 'normal allows more than gentle' => [80, 90, 70];
        yield 'fast allows more than normal' => [90, 70, 80];
    }
}
