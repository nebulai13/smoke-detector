<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Account;

use LogicException;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Rules\RuleSet;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\AccountUseCaseTestCase;
use SmokeDetector\Tests\Unit\Domain\Rules\RuleTables;

/**
 * GET /me (HLD §4.5 "Account"): the profile, and the plan with today's limit once there is one.
 */
final class ShowAccountTest extends AccountUseCaseTestCase
{
    public function test_FR_ACC_06_before_the_onboarding_the_account_is_the_profile_alone(): void
    {
        $user = $this->givenUser();

        $account = $this->showAccount()->execute($user->id);

        self::assertEquals($user, $account->user);
        self::assertNull($account->plan);
        self::assertFalse($account->onboardingComplete());
    }

    public function test_FR_ACC_06_after_the_onboarding_the_account_has_its_plan(): void
    {
        $user = $this->givenUser();
        $plan = $this->givenPlan($user, 12, Goal::reduceTo(5), TaperSpeed::Normal, ProductType::Rolled);

        $account = $this->showAccount()->execute($user->id);

        self::assertTrue($account->onboardingComplete());
        self::assertEquals($plan, $account->plan?->plan);
        self::assertSame(120, $account->plan?->baseline->tenths);
    }

    public function test_FR_PLAN_01_the_daily_limit_is_the_share_of_the_baseline_that_the_taper_speed_allows(): void
    {
        $limits = [];
        foreach ([TaperSpeed::Gentle, TaperSpeed::Normal, TaperSpeed::Fast] as $number => $speed) {
            $user = $this->givenUser("user{$number}@example.org");
            // 13 sessions per day: 11.7, 10.4 and 9.1, rounded down.
            $this->givenPlan($user, 13, Goal::quit(), $speed);
            $limits[$speed->value] = $this->showAccount()->execute($user->id)->plan?->dailyLimit;
        }

        self::assertSame(['gentle' => 11, 'normal' => 10, 'fast' => 9], $limits);
    }

    public function test_FR_PLAN_01_the_limit_follows_the_latest_baseline_of_the_history(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user, 12, Goal::quit(), TaperSpeed::Normal, startDate: '2026-11-02');
        $this->givenWeeklyBaseline($user, '2026-11-16', 74);
        $this->givenWeeklyBaseline($user, '2026-11-09', 98);

        $plan = $this->showAccount()->execute($user->id)->plan;

        // 80 % of 7.4 is 5.92.
        self::assertSame([74, 5], [$plan?->baseline->tenths, $plan?->dailyLimit]);
    }

    public function test_FR_PLAN_01_in_reduce_mode_the_limit_never_goes_below_the_target(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user, 12, Goal::reduceTo(6), TaperSpeed::Normal, startDate: '2026-11-02');
        $this->givenWeeklyBaseline($user, '2026-11-16', 74);

        self::assertSame(6, $this->showAccount()->execute($user->id)->plan?->dailyLimit);
    }

    public function test_FR_PLAN_01_the_limit_is_calculated_with_the_rule_set_in_force(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user, 12, Goal::quit(), TaperSpeed::Normal);
        $table = RuleTables::v1Array();
        self::assertIsArray($table['plan']);
        $table['plan']['normalLimitPercent'] = 50;
        $table['plan']['fastLimitPercent'] = 50;
        $this->rules = RuleSet::fromArray($table);

        self::assertSame(6, $this->showAccount()->execute($user->id)->plan?->dailyLimit);
    }

    public function test_the_plan_of_somebody_else_is_never_shown(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $bob = $this->givenUser('bob@example.org');
        $this->givenPlan($bob, 30);

        $account = $this->showAccount()->execute($anna->id);

        self::assertTrue($account->user->id->equals($anna->id));
        self::assertNull($account->plan);
    }

    public function test_FR_ACC_08_an_account_that_does_not_exist_any_more_is_reported_as_gone(): void
    {
        $this->givenUser();

        $this->expectException(AccountNotFound::class);
        $this->expectExceptionMessage('The account does not exist.');

        $this->showAccount()->execute(TestData::userId(999));
    }

    public function test_a_plan_without_any_baseline_is_a_broken_state_and_not_a_limit_of_zero(): void
    {
        $user = $this->givenUser();
        $this->givenPlanWithoutBaseline($user, new Plan($user->id, Goal::reduceTo(5), TaperSpeed::Normal, ProductType::Cigarette, LocalDate::fromString('2026-11-10'), 'v1'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A plan exists without a baseline history.');

        $this->showAccount()->execute($user->id);
    }
}
