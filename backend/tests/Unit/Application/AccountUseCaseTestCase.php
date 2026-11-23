<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application;

use SmokeDetector\Application\Account\ShowAccount;
use SmokeDetector\Application\Account\UpdateProfile;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\CurrentPlans;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Plan\SavePlan;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Rules\RuleSet;
use SmokeDetector\Tests\Support\Fake\FixedRuleSets;
use SmokeDetector\Tests\Support\InMemory\InMemoryBaselineHistory;
use SmokeDetector\Tests\Support\InMemory\InMemoryPlanRepository;
use SmokeDetector\Tests\Unit\Application\Auth\AuthUseCaseTestCase;
use SmokeDetector\Tests\Unit\Domain\Rules\RuleTables;

/**
 * The world of the account use cases (HLD §4.5 "Account"), without any I/O: what the auth use
 * cases have, plus in-memory plans and baseline history and the real rule table v1.
 */
abstract class AccountUseCaseTestCase extends AuthUseCaseTestCase
{
    protected InMemoryPlanRepository $plans;
    protected InMemoryBaselineHistory $baselines;
    protected RuleSet $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plans = new InMemoryPlanRepository($this->transactions);
        $this->baselines = new InMemoryBaselineHistory($this->transactions);
        $this->rules = RuleTables::v1();
    }

    protected function showAccount(): ShowAccount
    {
        return new ShowAccount($this->users, $this->currentPlans());
    }

    protected function updateProfile(): UpdateProfile
    {
        return new UpdateProfile($this->transactions, $this->users);
    }

    protected function savePlan(): SavePlan
    {
        return new SavePlan(
            $this->transactions,
            $this->clock,
            $this->users,
            $this->plans,
            $this->baselines,
            new FixedRuleSets($this->rules),
        );
    }

    protected function currentPlans(): CurrentPlans
    {
        return new CurrentPlans($this->plans, $this->baselines, new FixedRuleSets($this->rules));
    }

    /**
     * Stores a plan and its onboarding baseline directly, without going through the onboarding.
     *
     * @param int $startingBaseline In sessions per day.
     */
    protected function givenPlan(
        User $user,
        int $startingBaseline = 12,
        ?Goal $goal = null,
        TaperSpeed $taperSpeed = TaperSpeed::Normal,
        ProductType $defaultProduct = ProductType::Cigarette,
        string $startDate = '2026-11-10',
    ): Plan {
        $plan = new Plan($user->id, $goal ?? Goal::reduceTo(5), $taperSpeed, $defaultProduct, LocalDate::fromString($startDate), 'v1');
        $this->plans->save($plan);
        $this->baselines->append(BaselineEntry::onboarding($user->id, LocalDate::fromString($startDate), Baseline::fromTenths($startingBaseline * 10), 'v1'));

        return $plan;
    }

    /**
     * Stores what a weekly update would: a new baseline, valid from that day.
     */
    protected function givenWeeklyBaseline(User $user, string $validFrom, int $tenths): void
    {
        $this->baselines->append(new BaselineEntry($user->id, LocalDate::fromString($validFrom), Baseline::fromTenths($tenths), BaselineSource::Weekly, 7.0, 8.0, 7, 28, 'v1'));
    }
}
