<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Plan;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\GoalMode;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Plan\StartingBaseline;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Tests\Support\TestData;

/**
 * The values of a reduction plan as they are stored (HLD §4.3 "plans", "baseline_history").
 */
final class PlanValuesTest extends TestCase
{
    public function test_FR_ACC_06_the_starting_baseline_is_a_whole_number_from_1_to_80(): void
    {
        self::assertSame(10, StartingBaseline::fromInput(1)->tenths);
        self::assertSame(120, StartingBaseline::fromInput(12)->tenths);
        self::assertSame(800, StartingBaseline::fromInput(80)->tenths);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRange(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'one too many' => [81];
        yield 'far too many' => [PHP_INT_MAX];
    }

    #[DataProvider('outOfRange')]
    public function test_FR_ACC_06_a_starting_baseline_outside_1_to_80_is_rejected(int $sessionsPerDay): void
    {
        try {
            StartingBaseline::fromInput($sessionsPerDay);
            self::fail('Expected the baseline to be rejected.');
        } catch (InvalidValue $rejected) {
            self::assertSame([ErrorCode::OutOfRange, 'Must be a whole number from 1 to 80.'], [$rejected->errorCode, $rejected->getMessage()]);
        }
    }

    public function test_FR_ACC_06_the_goal_mode_follows_from_the_goal(): void
    {
        self::assertSame(GoalMode::Quit, GoalMode::of(Goal::quit()));
        self::assertSame(GoalMode::Reduce, GoalMode::of(Goal::reduceTo(1)));
        self::assertSame(['reduce', 'quit'], array_map(static fn (GoalMode $mode): string => $mode->value, GoalMode::cases()));
    }

    public function test_FR_ACC_06_the_product_types_are_those_of_the_design(): void
    {
        // HLD §4.3 "smoke_logs". A released value must stay: plans and logs have it stored.
        self::assertSame(
            ['cigarette', 'rolled', 'cigar', 'pipe', 'shisha', 'vape', 'heated_tobacco', 'other'],
            array_map(static fn (ProductType $type): string => $type->value, ProductType::cases()),
        );
    }

    public function test_FR_PLAN_03_changing_a_plan_keeps_its_user_start_date_and_rules_version(): void
    {
        $plan = new Plan(
            TestData::userId(1),
            Goal::reduceTo(5),
            TaperSpeed::Gentle,
            ProductType::Cigarette,
            LocalDate::fromString('2026-11-17'),
            'v1',
        );

        $changed = $plan->changedTo(Goal::quit(), TaperSpeed::Fast, ProductType::Vape);

        self::assertEquals(
            new Plan(TestData::userId(1), Goal::quit(), TaperSpeed::Fast, ProductType::Vape, LocalDate::fromString('2026-11-17'), 'v1'),
            $changed,
        );
        // The original is untouched.
        self::assertSame(5, $plan->goal->target);
    }

    public function test_FR_PLAN_02_the_onboarding_entry_has_no_inputs_of_a_weekly_update(): void
    {
        $entry = BaselineEntry::onboarding(TestData::userId(1), LocalDate::fromString('2026-11-17'), Baseline::fromTenths(120), 'v1');

        self::assertEquals(
            new BaselineEntry(TestData::userId(1), LocalDate::fromString('2026-11-17'), Baseline::fromTenths(120), BaselineSource::Onboarding, null, null, null, null, 'v1'),
            $entry,
        );
    }

    public function test_FR_PLAN_02_a_baseline_that_does_not_fit_the_history_is_refused_instead_of_cut(): void
    {
        $entry = static fn (int $tenths): BaselineEntry => BaselineEntry::onboarding(
            TestData::userId(1),
            LocalDate::fromString('2026-11-17'),
            Baseline::fromTenths($tenths),
            'v1',
        );

        // 999.9 sessions per day is what the column holds.
        self::assertSame(9999, $entry(9999)->value->tenths);
        self::assertSame(0, $entry(0)->value->tenths);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A baseline above 999.9 sessions per day cannot be stored.');

        $entry(10_000);
    }
}
