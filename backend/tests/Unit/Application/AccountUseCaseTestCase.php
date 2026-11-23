<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application;

use SmokeDetector\Application\Account\ShowAccount;
use SmokeDetector\Application\Account\UpdateProfile;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\CurrentPlans;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\PlanTerms;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Plan\SavePlan;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Time\LocalDays;
use SmokeDetector\Application\Time\ZoneChange;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Rules\RuleSet;
use SmokeDetector\Tests\Support\Fake\FixedRuleSets;
use SmokeDetector\Tests\Support\InMemory\InMemoryBaselineHistory;
use SmokeDetector\Tests\Support\InMemory\InMemoryPlanHistory;
use SmokeDetector\Tests\Support\InMemory\InMemoryPlanRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryProgressRecords;
use SmokeDetector\Tests\Support\InMemory\InMemoryZoneHistory;
use SmokeDetector\Tests\Unit\Application\Auth\AuthUseCaseTestCase;
use SmokeDetector\Tests\Unit\Domain\Rules\RuleTables;

/**
 * The world of the account use cases (HLD §4.5 "Account"), without any I/O: what the auth use
 * cases have, plus in-memory plans, baseline history, the histories of plan and time zone, the
 * progress of the users, and the real rule table v1.
 */
abstract class AccountUseCaseTestCase extends AuthUseCaseTestCase
{
    protected InMemoryPlanRepository $plans;
    protected InMemoryBaselineHistory $baselines;
    protected InMemoryPlanHistory $planHistory;
    protected InMemoryZoneHistory $zoneHistory;
    protected InMemoryProgressRecords $progressRecords;
    protected RuleSet $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plans = new InMemoryPlanRepository($this->transactions);
        $this->baselines = new InMemoryBaselineHistory($this->transactions);
        $this->planHistory = new InMemoryPlanHistory($this->transactions);
        $this->zoneHistory = new InMemoryZoneHistory($this->transactions);
        $this->progressRecords = new InMemoryProgressRecords($this->transactions);
        $this->rules = RuleTables::v1();
    }

    protected function showAccount(): ShowAccount
    {
        return new ShowAccount($this->users, $this->currentPlans());
    }

    protected function updateProfile(): UpdateProfile
    {
        return new UpdateProfile($this->transactions, $this->clock, $this->users, $this->plans, $this->zoneHistory, $this->progressRecords);
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
            $this->planHistory,
            $this->zoneHistory,
            $this->progressRecords,
        );
    }

    protected function currentPlans(): CurrentPlans
    {
        return new CurrentPlans($this->calendars(), $this->clock);
    }

    protected function calendars(): Calendars
    {
        return new Calendars($this->plans, $this->planHistory, $this->baselines, $this->zoneHistory, new FixedRuleSets($this->rules));
    }

    /**
     * Stores what the onboarding stores, directly: the plan, its starting baseline, the first
     * entries of the histories of plan and time zone, and progress with nothing earned yet.
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
        $this->givenPlanWithoutBaseline($user, $plan);
        $this->baselines->append(BaselineEntry::onboarding($user->id, LocalDate::fromString($startDate), Baseline::fromTenths($startingBaseline * 10), 'v1'));

        return $plan;
    }

    /**
     * Damaged data for the tests of exactly that: everything the onboarding stores except the
     * starting baseline.
     */
    protected function givenPlanWithoutBaseline(User $user, Plan $plan): void
    {
        $this->plans->save($plan);
        $this->planHistory->record(new PlanTerms($user->id, $plan->startDate, $plan->goal, $plan->taperSpeed, $plan->rulesVersion));
        $this->zoneHistory->record($user->id, new ZoneChange($plan->startDate, $user->timeZone));
        $days = new LocalDays($this->zoneHistory->ofUser($user->id));
        $this->progressRecords->save(new ProgressRecord(
            $user->id,
            UserProgress::initial(),
            0,
            $plan->startDate,
            $days->endOf($plan->startDate)->modify('+48 hours'),
            $days->startOf(LocalDate::fromString('2026-11-23')),
        ));
    }

    /**
     * Stores what a weekly update would: a new baseline, valid from that day.
     */
    protected function givenWeeklyBaseline(User $user, string $validFrom, int $tenths): void
    {
        $this->baselines->append(new BaselineEntry($user->id, LocalDate::fromString($validFrom), Baseline::fromTenths($tenths), BaselineSource::Weekly, 7.0, 8.0, 7, 28, 'v1'));
    }
}
