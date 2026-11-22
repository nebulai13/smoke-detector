<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use RuntimeException;

/**
 * The user has not done the onboarding (PUT /me/plan) yet. Logging, check-in, days and dashboard
 * need the plan: without it there is no first day and no limit. Answered as 409 "plan_required".
 */
final class PlanRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The user has no plan yet.');
    }
}
