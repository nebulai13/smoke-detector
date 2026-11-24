<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Recovery;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Logging\SmokeLogs;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Domain\Recovery\HealthMilestones;

/**
 * GET /me/health-milestones (SRS FR-GAME-09, HLD §4.10 "Health milestones"): the milestones by the
 * time since the user's last logged session, or without one since the end of the day the plan names
 * as the last session before joining. Worked out when asked and stored nowhere; for the user alone.
 */
final readonly class ShowHealthMilestones
{
    public function __construct(
        private ClockInterface $clock,
        private Calendars $calendars,
        private SmokeLogs $logs,
    ) {
    }

    /**
     * @throws PlanRequired The user has not done the onboarding.
     */
    public function of(UserId $userId): HealthView
    {
        $calendar = $this->calendars->of($userId) ?? throw new PlanRequired();
        $plan = $calendar->plan;
        // What was logged says more than what the plan names; the plan only speaks for a user without a log.
        $products = $this->logs->productsOf($userId);
        $vapingOnly = ($products === [] ? [$plan->defaultProduct] : $products) === [ProductType::Vape];
        $since = $this->logs->lastSessionAt($userId);
        if ($since === null && $plan->lastSessionBefore !== null) {
            // The end of the named day, so that the count never says more than the user did.
            $since = $calendar->days->endOf($plan->lastSessionBefore);
        }

        return new HealthView(
            $since,
            $vapingOnly,
            // The app does not know when somebody smoked last who never logged a session nor named a day.
            $since === null ? [] : HealthMilestones::at($since, $this->clock->now()),
        );
    }
}
