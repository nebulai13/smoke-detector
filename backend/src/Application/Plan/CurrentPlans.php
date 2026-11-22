<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use LogicException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Domain\Plan\ReductionPlan;

/**
 * Reads a user's plan and derives today's limit from it with the rules of the domain (FR-PLAN-01).
 */
final readonly class CurrentPlans
{
    public function __construct(
        private PlanRepository $plans,
        private BaselineHistory $baselines,
        private RuleSets $rules,
    ) {
    }

    /**
     * @return CurrentPlan|null Null before the onboarding.
     */
    public function of(UserId $userId): ?CurrentPlan
    {
        $plan = $this->plans->find($userId);
        if ($plan === null) {
            return null;
        }

        // The onboarding writes both in one transaction. One without the other is damaged data, and
        // must not be answered as "no limit" or "limit 0".
        $latest = $this->baselines->latest($userId);
        if ($latest === null) {
            throw new LogicException('A plan exists without a baseline history.');
        }

        return new CurrentPlan(
            $plan,
            $latest->value,
            ReductionPlan::dailyLimit($latest->value, $plan->taperSpeed, $plan->goal, $this->rules->current()->plan),
        );
    }
}
