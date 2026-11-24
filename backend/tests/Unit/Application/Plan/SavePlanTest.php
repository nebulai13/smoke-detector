<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Plan;

use DateTimeImmutable;
use LogicException;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\ProfileChange;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\PlanChoice;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Rules\RuleSet;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\AccountUseCaseTestCase;
use SmokeDetector\Tests\Unit\Domain\Rules\RuleTables;

/**
 * PUT /me/plan (HLD §4.5 "Account", FR-ACC-06, FR-PLAN-02, FR-PLAN-03): the onboarding, and the
 * changes a user may make afterwards.
 */
final class SavePlanTest extends AccountUseCaseTestCase
{
    public function test_FR_ACC_06_the_onboarding_stores_goal_taper_speed_and_usual_product(): void
    {
        $user = $this->givenUser();

        $this->savePlan()->execute($user->id, new PlanChoice(12, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::Rolled));

        self::assertEquals(
            new Plan($user->id, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::Rolled, LocalDate::fromString('2026-11-17'), 'v1'),
            $this->plans->find($user->id),
        );
    }

    public function test_FR_PLAN_02_the_onboarding_writes_the_first_entry_of_the_baseline_history(): void
    {
        $user = $this->givenUser();

        $this->savePlan()->execute($user->id, new PlanChoice(12, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette));

        self::assertEquals(
            [new BaselineEntry($user->id, LocalDate::fromString('2026-11-17'), Baseline::fromTenths(120), BaselineSource::Onboarding, null, null, null, null, 'v1')],
            $this->baselines->all(),
        );
    }

    public function test_FR_PLAN_02_the_baseline_counts_from_the_users_own_day_not_the_servers(): void
    {
        // One second before 10:00 UTC it is 23:59:59 of the 17th on Kiritimati (UTC+14) and 23:59:59
        // of the 16th in Honolulu (UTC-10).
        $ahead = $this->givenUserIn('ahead@example.org', 'Pacific/Kiritimati');
        $behind = $this->givenUserIn('behind@example.org', 'Pacific/Honolulu');
        $this->clock->travelTo(new DateTimeImmutable('2026-11-17 09:59:59 UTC'));
        $this->savePlan()->execute($ahead->id, self::choice());
        $this->savePlan()->execute($behind->id, self::choice());

        self::assertSame('2026-11-17', $this->baselines->latest($ahead->id)?->validFrom->value);
        self::assertSame('2026-11-17', $this->plans->find($ahead->id)?->startDate->value);
        self::assertSame('2026-11-16', $this->baselines->latest($behind->id)?->validFrom->value);
        self::assertSame('2026-11-16', $this->plans->find($behind->id)?->startDate->value);
    }

    public function test_FR_PLAN_02_plan_and_baseline_name_the_version_of_the_rule_set_in_force(): void
    {
        $user = $this->givenUser();
        $this->rules = RuleSet::fromArray(['version' => 'v7-test'] + RuleTables::v1Array());

        $this->savePlan()->execute($user->id, self::choice());

        self::assertSame('v7-test', $this->plans->find($user->id)?->rulesVersion);
        self::assertSame('v7-test', $this->baselines->latest($user->id)?->rulesVersion);
    }

    public function test_FR_ACC_06_the_edges_of_the_starting_baseline_are_accepted(): void
    {
        $low = $this->givenUser('low@example.org');
        $high = $this->givenUser('high@example.org');

        $this->savePlan()->execute($low->id, new PlanChoice(1, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette));
        $this->savePlan()->execute($high->id, new PlanChoice(80, Goal::reduceTo(79), TaperSpeed::Normal, ProductType::Cigarette));

        self::assertSame(10, $this->baselines->latest($low->id)?->value->tenths);
        self::assertSame(800, $this->baselines->latest($high->id)?->value->tenths);
    }

    public function test_FR_ACC_06_a_starting_baseline_outside_1_to_80_is_rejected(): void
    {
        $user = $this->givenUser();

        foreach ([0, 81, -5] as $baseline) {
            $this->assertRejected(
                $user,
                new PlanChoice($baseline, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette),
                'startingBaseline',
                ErrorCode::OutOfRange,
            );
        }
        $this->assertNothingStored();
    }

    public function test_FR_ACC_06_the_onboarding_needs_a_starting_baseline(): void
    {
        $user = $this->givenUser();

        $this->assertRejected(
            $user,
            new PlanChoice(null, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette),
            'startingBaseline',
            ErrorCode::Required,
        );
        $this->assertNothingStored();
    }

    public function test_FR_ACC_06_a_reduce_target_must_be_below_the_starting_baseline(): void
    {
        $user = $this->givenUser();

        foreach ([12, 13, 80] as $target) {
            $this->assertRejected(
                $user,
                new PlanChoice(12, Goal::reduceTo($target), TaperSpeed::Normal, ProductType::Cigarette),
                'target',
                ErrorCode::MustBeBelowBaseline,
            );
        }
        $this->assertNothingStored();

        // One below is fine.
        $this->savePlan()->execute($user->id, new PlanChoice(12, Goal::reduceTo(11), TaperSpeed::Normal, ProductType::Cigarette));
        self::assertSame(11, $this->plans->find($user->id)?->goal->target);
    }

    public function test_FR_ACC_06_with_a_baseline_of_one_session_only_quitting_is_left(): void
    {
        $user = $this->givenUser();

        $this->assertRejected(
            $user,
            new PlanChoice(1, Goal::reduceTo(1), TaperSpeed::Normal, ProductType::Cigarette),
            'target',
            ErrorCode::MustBeBelowBaseline,
        );
    }

    public function test_FR_ACC_06_a_rejected_onboarding_stores_neither_plan_nor_baseline(): void
    {
        $user = $this->givenUser();

        $this->assertRejected($user, new PlanChoice(12, Goal::reduceTo(12), TaperSpeed::Normal, ProductType::Cigarette), 'target', ErrorCode::MustBeBelowBaseline);

        $this->assertNothingStored();
        self::assertSame(0, $this->transactions->commits);
    }

    public function test_FR_ACC_06_goal_taper_speed_and_product_can_be_changed_later(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user, 12, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::Cigarette, '2026-11-10');

        $this->savePlan()->execute($user->id, new PlanChoice(null, Goal::reduceTo(3), TaperSpeed::Fast, ProductType::Vape));

        self::assertEquals(
            // The start date stays the day of the onboarding.
            new Plan($user->id, Goal::reduceTo(3), TaperSpeed::Fast, ProductType::Vape, LocalDate::fromString('2026-11-10'), 'v1'),
            $this->plans->find($user->id),
        );
    }

    public function test_FR_PLAN_03_switching_from_reduce_to_quit_resets_nothing(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user, 12, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::Pipe, '2026-11-02');
        $this->givenWeeklyBaseline($user, '2026-11-09', 98);
        $history = $this->baselines->all();

        $this->savePlan()->execute($user->id, new PlanChoice(null, Goal::quit(), TaperSpeed::Gentle, ProductType::Pipe));

        self::assertEquals(
            new Plan($user->id, Goal::quit(), TaperSpeed::Gentle, ProductType::Pipe, LocalDate::fromString('2026-11-02'), 'v1'),
            $this->plans->find($user->id),
        );
        self::assertEquals($history, $this->baselines->all());
    }

    public function test_FR_PLAN_02_a_later_change_never_touches_the_baseline_history(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user, 12);
        $this->givenWeeklyBaseline($user, '2026-11-16', 74);
        $history = $this->baselines->all();

        $this->savePlan()->execute($user->id, new PlanChoice(null, Goal::reduceTo(4), TaperSpeed::Fast, ProductType::Vape));
        $this->savePlan()->execute($user->id, new PlanChoice(12, Goal::quit(), TaperSpeed::Normal, ProductType::Vape));

        self::assertEquals($history, $this->baselines->all());
    }

    public function test_FR_ACC_06_the_baseline_cannot_be_set_a_second_time(): void
    {
        $user = $this->givenUser();
        $plan = $this->givenPlan($user, 12);
        $history = $this->baselines->all();

        foreach ([11, 13, 1, 80] as $other) {
            $this->assertRejected(
                $user,
                new PlanChoice($other, Goal::quit(), TaperSpeed::Fast, ProductType::Vape),
                'startingBaseline',
                ErrorCode::BaselineAlreadySet,
            );
        }

        // And nothing else of the rejected request was stored either.
        self::assertEquals($plan, $this->plans->find($user->id));
        self::assertEquals($history, $this->baselines->all());
    }

    public function test_FR_ACC_06_repeating_the_starting_baseline_of_the_onboarding_is_accepted(): void
    {
        // A client that did not get the answer to its onboarding request sends it again.
        $user = $this->givenUser();
        $this->savePlan()->execute($user->id, self::choice());
        $this->clock->advanceSeconds(3 * 86_400);

        $this->savePlan()->execute($user->id, self::choice());

        self::assertCount(1, $this->baselines->all());
        self::assertSame('2026-11-17', $this->plans->find($user->id)?->startDate->value);
    }

    public function test_FR_ACC_06_without_an_onboarding_entry_no_baseline_can_be_repeated(): void
    {
        // Damaged data: a plan whose history lacks the onboarding value. Nothing can be "the same" then.
        $user = $this->givenUser();
        $this->givenPlanWithoutBaseline($user, new Plan($user->id, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette, LocalDate::fromString('2026-11-02'), 'v1'));
        $this->givenWeeklyBaseline($user, '2026-11-09', 120);

        $this->assertRejected(
            $user,
            new PlanChoice(12, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette),
            'startingBaseline',
            ErrorCode::BaselineAlreadySet,
        );
    }

    public function test_a_plan_without_any_baseline_cannot_get_a_new_goal(): void
    {
        $user = $this->givenUser();
        $this->givenPlanWithoutBaseline($user, new Plan($user->id, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette, LocalDate::fromString('2026-11-02'), 'v1'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A plan exists without a baseline history.');

        $this->savePlan()->execute($user->id, new PlanChoice(null, Goal::reduceTo(3), TaperSpeed::Normal, ProductType::Cigarette));
    }

    public function test_FR_ACC_06_the_value_to_repeat_is_the_one_of_the_onboarding_not_the_current_baseline(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user, 12, Goal::quit(), startDate: '2026-11-02');
        $this->givenWeeklyBaseline($user, '2026-11-09', 90);

        // 9 is the current baseline, but it was never what the user stated.
        $this->assertRejected(
            $user,
            new PlanChoice(9, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette),
            'startingBaseline',
            ErrorCode::BaselineAlreadySet,
        );
        $this->savePlan()->execute($user->id, new PlanChoice(12, Goal::quit(), TaperSpeed::Fast, ProductType::Cigarette));
        self::assertSame(TaperSpeed::Fast, $this->plans->find($user->id)?->taperSpeed);
    }

    public function test_FR_ACC_06_a_changed_reduce_target_must_be_below_the_current_baseline(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user, 12, Goal::reduceTo(5), startDate: '2026-11-02');
        $this->givenWeeklyBaseline($user, '2026-11-09', 74);

        // Below the starting baseline of 12, but not below the current 7.4.
        foreach ([8, 11] as $target) {
            $this->assertRejected(
                $user,
                new PlanChoice(null, Goal::reduceTo($target), TaperSpeed::Normal, ProductType::Cigarette),
                'target',
                ErrorCode::MustBeBelowBaseline,
            );
        }
        self::assertSame(5, $this->plans->find($user->id)?->goal->target);

        $this->savePlan()->execute($user->id, new PlanChoice(null, Goal::reduceTo(7), TaperSpeed::Normal, ProductType::Cigarette));
        self::assertSame(7, $this->plans->find($user->id)?->goal->target);
    }

    public function test_FR_ACC_06_a_target_equal_to_a_whole_current_baseline_is_not_below_it(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user, 12, Goal::reduceTo(5), startDate: '2026-11-02');
        $this->givenWeeklyBaseline($user, '2026-11-09', 70);

        $this->assertRejected(
            $user,
            new PlanChoice(null, Goal::reduceTo(7), TaperSpeed::Normal, ProductType::Cigarette),
            'target',
            ErrorCode::MustBeBelowBaseline,
        );
        $this->savePlan()->execute($user->id, new PlanChoice(null, Goal::reduceTo(6), TaperSpeed::Normal, ProductType::Cigarette));
        self::assertSame(6, $this->plans->find($user->id)?->goal->target);
    }

    public function test_FR_ACC_06_a_goal_that_is_not_changed_is_not_judged_again(): void
    {
        // The baseline fell below the target the user once chose. Changing the taper speed must
        // still work: the rule is about setting a goal, and the limit never goes below the target.
        $user = $this->givenUser();
        $this->givenPlan($user, 12, Goal::reduceTo(5), TaperSpeed::Normal, startDate: '2026-11-02');
        $this->givenWeeklyBaseline($user, '2026-11-09', 45);

        $this->savePlan()->execute($user->id, new PlanChoice(null, Goal::reduceTo(5), TaperSpeed::Fast, ProductType::Shisha));

        self::assertEquals(
            new Plan($user->id, Goal::reduceTo(5), TaperSpeed::Fast, ProductType::Shisha, LocalDate::fromString('2026-11-02'), 'v1'),
            $this->plans->find($user->id),
        );
    }

    public function test_FR_PLAN_03_quitting_is_always_possible_whatever_the_baseline(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user, 12, Goal::reduceTo(5), startDate: '2026-11-02');
        $this->givenWeeklyBaseline($user, '2026-11-09', 0);

        $this->savePlan()->execute($user->id, new PlanChoice(null, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette));

        self::assertTrue($this->plans->find($user->id)?->goal->isQuit());
    }

    public function test_FR_ACC_06_the_plan_of_somebody_else_is_never_touched(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $bob = $this->givenUser('bob@example.org');
        $bobsPlan = $this->givenPlan($bob, 30, Goal::reduceTo(20));

        $this->savePlan()->execute($anna->id, self::choice());
        $this->savePlan()->execute($anna->id, new PlanChoice(null, Goal::quit(), TaperSpeed::Fast, ProductType::Vape));

        self::assertEquals($bobsPlan, $this->plans->find($bob->id));
        self::assertCount(1, $this->baselines->ofUser($bob->id));
        self::assertCount(1, $this->baselines->ofUser($anna->id));
    }

    public function test_plan_and_baseline_are_stored_in_one_transaction(): void
    {
        $user = $this->givenUser();

        $this->savePlan()->execute($user->id, self::choice());

        self::assertSame([1, 0], [$this->transactions->commits, $this->transactions->rollbacks]);
    }

    public function test_FR_ACC_08_an_account_that_does_not_exist_any_more_is_reported_as_gone(): void
    {
        $this->givenUser();

        $this->expectException(AccountNotFound::class);

        $this->savePlan()->execute(TestData::userId(999), self::choice());
    }

    public function test_FR_GAME_09_the_onboarding_takes_the_day_of_the_last_session_before_joining(): void
    {
        $user = $this->givenUser();

        $this->savePlan()->execute($user->id, new PlanChoice(12, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette, LocalDate::fromString('2026-10-31')));

        self::assertEquals(
            new Plan($user->id, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette, LocalDate::fromString('2026-11-17'), 'v1', LocalDate::fromString('2026-10-31')),
            $this->plans->find($user->id),
        );
    }

    public function test_FR_GAME_09_the_last_session_before_joining_is_before_the_first_day_of_the_plan(): void
    {
        $user = $this->givenUser();
        // Today is the 17th, the first day of the plan. A session of today is logged, not stated.
        foreach (['2026-11-17', '2026-11-18', '1899-12-31'] as $day) {
            $this->assertRejected($user, new PlanChoice(12, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette, LocalDate::fromString($day)), 'lastSessionBefore', ErrorCode::OutOfRange);
        }
        $this->assertNothingStored();

        // The edges.
        $this->savePlan()->execute($user->id, new PlanChoice(12, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette, LocalDate::fromString('2026-11-16')));
        self::assertEquals(LocalDate::fromString('2026-11-16'), $this->plans->find($user->id)?->lastSessionBefore);
        $other = $this->givenUser('old@example.org');
        $this->savePlan()->execute($other->id, new PlanChoice(12, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette, LocalDate::fromString('1900-01-01')));
        self::assertEquals(LocalDate::fromString('1900-01-01'), $this->plans->find($other->id)?->lastSessionBefore);
    }

    public function test_FR_GAME_09_the_day_can_be_stated_changed_or_taken_away_later_and_stays_before_the_first_day(): void
    {
        $user = $this->givenUser();
        $this->savePlan()->execute($user->id, self::choice());
        $this->clock->advanceSeconds(10 * 86_400);

        $this->savePlan()->execute($user->id, new PlanChoice(null, Goal::reduceTo(5), TaperSpeed::Normal, ProductType::Cigarette, LocalDate::fromString('2026-09-01')));
        self::assertEquals(LocalDate::fromString('2026-09-01'), $this->plans->find($user->id)?->lastSessionBefore);

        // Ten days after the onboarding, a day after it began is no day before joining.
        $this->assertRejected($user, new PlanChoice(null, Goal::reduceTo(5), TaperSpeed::Normal, ProductType::Cigarette, LocalDate::fromString('2026-11-20')), 'lastSessionBefore', ErrorCode::OutOfRange);
        self::assertEquals(LocalDate::fromString('2026-09-01'), $this->plans->find($user->id)?->lastSessionBefore);

        // A plan sent without it has none: PUT replaces the plan.
        $this->savePlan()->execute($user->id, self::choice());
        self::assertNull($this->plans->find($user->id)?->lastSessionBefore);
        // Nothing else of the plan changed.
        self::assertEquals(LocalDate::fromString('2026-11-17'), $this->plans->find($user->id)?->startDate);
    }

    private static function choice(): PlanChoice
    {
        return new PlanChoice(12, Goal::reduceTo(5), TaperSpeed::Normal, ProductType::Cigarette);
    }

    private function givenUserIn(string $email, string $timeZone): User
    {
        $user = (new ProfileChange(timeZone: TimeZone::fromInput($timeZone)))->applyTo($this->givenUser($email));
        $this->users->update($user);

        return $user;
    }

    private function assertRejected(User $user, PlanChoice $choice, string $field, ErrorCode $code): void
    {
        try {
            $this->savePlan()->execute($user->id, $choice);
            self::fail("Expected the plan to be rejected for {$field}.");
        } catch (ValidationFailed $rejected) {
            self::assertCount(1, $rejected->errors);
            self::assertSame([$field, $code], [$rejected->errors[0]->field, $rejected->errors[0]->code]);
        }
    }

    private function assertNothingStored(): void
    {
        self::assertSame([], $this->baselines->all());
        foreach ($this->users->all() as $user) {
            self::assertNull($this->plans->find($user->id));
        }
    }
}
