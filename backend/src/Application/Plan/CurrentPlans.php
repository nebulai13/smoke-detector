<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\Calendars;

/**
 * Reads a user's plan and derives today's limit from it with the rules of the domain (FR-PLAN-01),
 * on the user's own today (HLD §4.6 "Plan and limit").
 */
final readonly class CurrentPlans
{
    public function __construct(
        private Calendars $calendars,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return CurrentPlan|null Null before the onboarding.
     */
    public function of(UserId $userId): ?CurrentPlan
    {
        $calendar = $this->calendars->of($userId);
        if ($calendar === null) {
            return null;
        }
        $today = $calendar->today($this->clock->now());

        return new CurrentPlan(
            $calendar->plan,
            $calendar->baselineOn($today)->value,
            $calendar->limitOn($today),
            $calendar->pendingFrom($today),
        );
    }
}
