<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Competition;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Competition\CompetitionRules;
use SmokeDetector\Domain\Competition\FrozenBaseline;
use SmokeDetector\Domain\Plan\Baseline;

/**
 * The baseline a competition scores against, SRS FR-PLAN-07.
 */
final class FrozenBaselineTest extends TestCase
{
    public function test_FR_PLAN_07_baseline_above_the_minimum_is_frozen_unchanged(): void
    {
        $frozen = FrozenBaseline::freeze(Baseline::of(7.4), new CompetitionRules());

        self::assertSame(74, $frozen->tenths);
        self::assertSame(7.4, $frozen->toFloat());
    }

    public function test_FR_PLAN_07_baseline_of_exactly_1_0_is_frozen_unchanged(): void
    {
        self::assertSame(10, FrozenBaseline::freeze(Baseline::of(1.0), new CompetitionRules())->tenths);
        self::assertSame(11, FrozenBaseline::freeze(Baseline::of(1.1), new CompetitionRules())->tenths);
    }

    public function test_FR_PLAN_07_baseline_below_1_0_scores_as_1_0(): void
    {
        $rules = new CompetitionRules();

        self::assertSame(10, FrozenBaseline::freeze(Baseline::of(0.3), $rules)->tenths);
        self::assertSame(10, FrozenBaseline::freeze(Baseline::of(0.9), $rules)->tenths);
        // A quitter at 0.0 can still compete: without the minimum the expected total would be zero.
        self::assertSame(10, FrozenBaseline::freeze(Baseline::of(0.0), $rules)->tenths);
    }

    public function test_FR_PLAN_07_default_scoring_minimum_is_1_0(): void
    {
        self::assertSame(10, (new CompetitionRules())->minScoringBaselineTenths);
    }

    public function test_FR_PLAN_07_scoring_minimum_comes_from_the_rules(): void
    {
        $rules = new CompetitionRules(minScoringBaselineTenths: 25);

        self::assertSame(25, FrozenBaseline::freeze(Baseline::of(1.5), $rules)->tenths);
        self::assertSame(26, FrozenBaseline::freeze(Baseline::of(2.6), $rules)->tenths);
    }

    public function test_FR_PLAN_07_freezing_a_stored_frozen_value_again_gives_the_same_value(): void
    {
        // A duel stores B0 and reloads it later. Reloading must not move the value.
        $rules = new CompetitionRules();
        $first = FrozenBaseline::freeze(Baseline::of(0.3), $rules);

        $reloaded = FrozenBaseline::freeze(Baseline::fromTenths($first->tenths), $rules);

        self::assertSame($first->tenths, $reloaded->tenths);
    }

    public function test_FR_PLAN_07_smallest_scoring_minimum_is_one_tenth_of_a_session(): void
    {
        $rules = new CompetitionRules(minScoringBaselineTenths: 1);

        self::assertSame(1, $rules->minScoringBaselineTenths);
        self::assertSame(1, FrozenBaseline::freeze(Baseline::of(0.0), $rules)->tenths);
    }

    public function test_FR_PLAN_07_largest_scoring_minimum_is_100_sessions_a_day(): void
    {
        $rules = new CompetitionRules(minScoringBaselineTenths: CompetitionRules::MAX_SCORING_MINIMUM_TENTHS);

        self::assertSame(1000, $rules->minScoringBaselineTenths);
        self::assertSame(1000, FrozenBaseline::freeze(Baseline::of(80.0), $rules)->tenths);
    }

    public function test_FR_PLAN_07_scoring_minimum_above_the_documented_maximum_is_rejected(): void
    {
        // Such a minimum would replace every real baseline, and a huge one could not be scored at all.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The scoring minimum of the baseline cannot be above 1000 tenths.');

        new CompetitionRules(minScoringBaselineTenths: CompetitionRules::MAX_SCORING_MINIMUM_TENTHS + 1);
    }

    public function test_FR_PLAN_07_scoring_minimum_of_zero_is_rejected(): void
    {
        // A zero baseline would make the expected total zero and the reduction undefined.
        $this->expectException(InvalidArgumentException::class);

        new CompetitionRules(minScoringBaselineTenths: 0);
    }

    public function test_FR_PLAN_07_negative_scoring_minimum_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CompetitionRules(minScoringBaselineTenths: -10);
    }

    public function test_NFR_04_freezing_the_same_baseline_twice_gives_the_same_result(): void
    {
        $rules = new CompetitionRules();

        self::assertEquals(
            FrozenBaseline::freeze(Baseline::of(0.3), $rules),
            FrozenBaseline::freeze(Baseline::of(0.3), $rules),
        );
        self::assertEquals(
            FrozenBaseline::freeze(Baseline::of(12.6), $rules),
            FrozenBaseline::freeze(Baseline::of(12.6), new CompetitionRules()),
        );
    }
}
