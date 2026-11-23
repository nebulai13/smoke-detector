<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Application\Account\UserId;

/**
 * The terms of the users' plans and from which local date each counts (HLD §4.3 "plan_history").
 */
interface PlanHistory
{
    /**
     * Stores the terms. Terms of the same user for the same date are replaced: a second change on
     * one day replaces the first, which has not counted yet.
     */
    public function record(PlanTerms $terms): void;

    /**
     * @return list<PlanTerms> Oldest first. Empty for a user without a plan.
     */
    public function ofUser(UserId $userId): array;
}
