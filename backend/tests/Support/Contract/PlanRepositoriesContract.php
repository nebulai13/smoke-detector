<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\BaselineAlreadyRecorded;
use SmokeDetector\Application\Plan\BaselineHistory;
use SmokeDetector\Application\Plan\PlanRepository;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every PlanRepository and every BaselineHistory does. The in-memory ones and the PDO ones
 * both run these tests.
 */
trait PlanRepositoriesContract
{
    abstract protected function plans(): PlanRepository;

    abstract protected function baselines(): BaselineHistory;

    /** Makes sure the accounts exist, where rows cannot exist without one. */
    abstract protected function givenUsers(UserId ...$ids): void;

    public function test_FR_ACC_06_a_saved_plan_is_found_with_every_value(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $reduce = TestData::plan(1, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::HeatedTobacco, '2026-11-17', 'v1');
        $quit = TestData::plan(2, Goal::quit(), TaperSpeed::Fast, ProductType::Other, '2026-02-28', 'v2.beta');

        $this->plans()->save($reduce);
        $this->plans()->save($quit);

        self::assertEquals($reduce, $this->plans()->find(TestData::userId(1)));
        self::assertEquals($quit, $this->plans()->find(TestData::userId(2)));
    }

    public function test_a_user_without_a_plan_has_none(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->plans()->save(TestData::plan(1));

        self::assertNull($this->plans()->find(TestData::userId(2)));
    }

    public function test_FR_PLAN_03_saving_again_replaces_the_plan_of_that_user_only(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->plans()->save(TestData::plan(1, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::Cigarette));
        $this->plans()->save(TestData::plan(2, Goal::reduceTo(3)));

        $changed = TestData::plan(1, Goal::quit(), TaperSpeed::Normal, ProductType::Vape);
        $this->plans()->save($changed);

        self::assertEquals($changed, $this->plans()->find(TestData::userId(1)));
        self::assertEquals(TestData::plan(2, Goal::reduceTo(3)), $this->plans()->find(TestData::userId(2)));
    }

    public function test_FR_PLAN_02_baseline_entries_are_kept_with_their_date_value_source_and_inputs(): void
    {
        $this->givenUsers(TestData::userId(1));
        $onboarding = TestData::baseline(1, '2026-11-17', 120);
        // The means are stored exactly as they were calculated, not rounded.
        $weekly = TestData::baseline(1, '2026-11-23', 74, BaselineSource::Weekly, 7.428571428571429, 0.1, 7, 28, 'v2');
        $lowData = TestData::baseline(1, '2026-11-30', 74, BaselineSource::UnchangedLowData, null, 6.5, 0, 12);
        $capped = TestData::baseline(1, '2026-12-07', 81, BaselineSource::Capped, 20.0, 9.0, 4, 4);

        foreach ([$onboarding, $weekly, $lowData, $capped] as $entry) {
            $this->baselines()->append($entry);
        }

        self::assertEquals([$onboarding, $weekly, $lowData, $capped], $this->baselines()->ofUser(TestData::userId(1)));
    }

    public function test_FR_PLAN_02_the_history_is_read_oldest_first_whatever_the_order_of_writing(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->baselines()->append(TestData::baseline(1, '2026-11-23', 74, BaselineSource::Weekly));
        $this->baselines()->append(TestData::baseline(2, '2026-11-20', 300));
        $this->baselines()->append(TestData::baseline(1, '2026-11-17', 120));
        $this->baselines()->append(TestData::baseline(1, '2026-12-07', 0, BaselineSource::Weekly));

        self::assertSame(
            ['2026-11-17', '2026-11-23', '2026-12-07'],
            array_map(static fn ($entry): string => $entry->validFrom->value, $this->baselines()->ofUser(TestData::userId(1))),
        );
        self::assertSame([], $this->baselines()->ofUser(TestData::userId(3)));
    }

    public function test_FR_PLAN_02_the_current_baseline_is_the_entry_with_the_latest_date(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->baselines()->append(TestData::baseline(1, '2026-11-23', 74, BaselineSource::Weekly));
        $this->baselines()->append(TestData::baseline(1, '2026-11-17', 120));
        // A later date and a higher value, but of somebody else.
        $this->baselines()->append(TestData::baseline(2, '2026-12-01', 800));

        self::assertEquals(TestData::baseline(1, '2026-11-23', 74, BaselineSource::Weekly), $this->baselines()->latest(TestData::userId(1)));
        self::assertEquals(TestData::baseline(2, '2026-12-01', 800), $this->baselines()->latest(TestData::userId(2)));
        self::assertNull($this->baselines()->latest(TestData::userId(3)));
    }

    public function test_FR_ACC_06_the_starting_baseline_is_the_onboarding_entry_however_many_follow(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->baselines()->append(TestData::baseline(2, '2026-11-01', 300));
        $this->baselines()->append(TestData::baseline(1, '2026-11-17', 120));
        $this->baselines()->append(TestData::baseline(1, '2026-11-23', 74, BaselineSource::Weekly));
        $this->baselines()->append(TestData::baseline(1, '2026-11-30', 74, BaselineSource::UnchangedLowData));

        self::assertEquals(TestData::baseline(1, '2026-11-17', 120), $this->baselines()->starting(TestData::userId(1)));
        self::assertEquals(TestData::baseline(2, '2026-11-01', 300), $this->baselines()->starting(TestData::userId(2)));
        self::assertNull($this->baselines()->starting(TestData::userId(3)));
    }

    public function test_FR_PLAN_02_an_entry_is_never_replaced_a_second_one_for_the_same_day_is_refused(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->baselines()->append(TestData::baseline(1, '2026-11-17', 120));
        // The same day for another user is fine.
        $this->baselines()->append(TestData::baseline(2, '2026-11-17', 50));

        try {
            $this->baselines()->append(TestData::baseline(1, '2026-11-17', 60, BaselineSource::Weekly));
            self::fail('Expected the second entry for the same day to be refused.');
        } catch (BaselineAlreadyRecorded $refused) {
            self::assertSame('The baseline history already has an entry for that user and day.', $refused->getMessage());
        }

        self::assertEquals([TestData::baseline(1, '2026-11-17', 120)], $this->baselines()->ofUser(TestData::userId(1)));
    }
}
