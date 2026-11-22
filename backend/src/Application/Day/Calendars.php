<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use LogicException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\BaselineHistory;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\PlanHistory;
use SmokeDetector\Application\Plan\PlanRepository;
use SmokeDetector\Application\Plan\RuleSets;
use SmokeDetector\Application\Time\LocalDays;
use SmokeDetector\Application\Time\ZoneHistory;

/**
 * Reads what the Calendar of a user is made of: plan, the histories of baseline, plan terms and
 * time zone, and the rules in force.
 */
final readonly class Calendars
{
    public function __construct(
        private PlanRepository $plans,
        private PlanHistory $planHistory,
        private BaselineHistory $baselines,
        private ZoneHistory $zones,
        private RuleSets $rules,
    ) {
    }

    /**
     * @return Calendar|null Null before the onboarding.
     */
    public function of(UserId $userId): ?Calendar
    {
        $plan = $this->plans->find($userId);

        return $plan === null ? null : $this->ofPlan($plan);
    }

    /**
     * The calendar of the user whose plan it is, for a caller that has read the plan already.
     */
    public function ofPlan(Plan $plan): Calendar
    {
        $userId = $plan->userId;
        // The onboarding writes plan and baseline in one transaction. One without the other is
        // damaged data, and must not be answered as "no limit" or "limit 0".
        $baselines = $this->baselines->ofUser($userId);
        if ($baselines === []) {
            throw new LogicException('A plan exists without a baseline history.');
        }

        return new Calendar(
            $plan,
            new LocalDays($this->zones->ofUser($userId)),
            $baselines,
            $this->planHistory->ofUser($userId),
            $this->rules->current(),
        );
    }
}
