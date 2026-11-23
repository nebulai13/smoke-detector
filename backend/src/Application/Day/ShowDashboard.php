<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use LogicException;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\PlanRequired;

/**
 * GET /me/dashboard (HLD §4.6, FR-LOG-05, FR-GAME-02 to -06): the user's today against its limit,
 * and streaks, XP and level with the open days projected.
 */
final readonly class ShowDashboard
{
    public function __construct(
        private ClockInterface $clock,
        private Calendars $calendars,
        private DayRecords $days,
        private ProgressRecords $progress,
    ) {
    }

    /**
     * @throws PlanRequired Before the onboarding.
     */
    public function execute(UserId $userId): Dashboard
    {
        $calendar = $this->calendars->of($userId) ?? throw new PlanRequired();
        $stored = $this->progress->find($userId) ?? throw new LogicException('A plan exists without progress.');
        $today = $calendar->today($this->clock->now());

        $projection = Projection::of($calendar, $stored, $this->days->between($userId, $stored->nextDay, $today), $today);

        return new Dashboard(
            $calendar->dayView($today, $this->days->find($userId, $today), $stored->nextDay),
            $projection->view(),
        );
    }
}
