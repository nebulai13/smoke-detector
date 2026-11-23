<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Application\Account\UserId;

/**
 * The reduction plans (HLD §4.3 "plans"): at most one per user.
 */
interface PlanRepository
{
    public function find(UserId $userId): ?Plan;

    /**
     * Stores the plan as the one plan of its user, in place of an earlier one.
     */
    public function save(Plan $plan): void;
}
