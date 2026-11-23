<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Recovery;

use SmokeDetector\Application\Day\Calendar;
use SmokeDetector\Application\Logging\SmokeLogs;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Recovery\HealthMilestones;

/**
 * Stores the health milestones a user has reached for good (HLD §4.11 "Milestones for friends",
 * SRS FR-GAME-09, FR-FRND-03): from the last session logged for a final day or a day before it, to
 * the end of that day.
 *
 * It runs inside the transaction that makes the day final, so a day that is rolled back leaves no
 * milestone behind. Only final days count: a session of an open day is no friend's business. What
 * was reached once stays with its first moment, so a relapse does not show.
 */
final readonly class AwardMilestones
{
    public function __construct(
        private MilestoneRecords $milestones,
        private SmokeLogs $logs,
    ) {
    }

    /**
     * After a day of the user of the calendar has become final.
     */
    public function dayFinalized(Calendar $calendar, LocalDate $date): void
    {
        $userId = $calendar->plan->userId;
        $since = $this->logs->lastSessionUntil($userId, $date);
        if ($since === null) {
            // Without a logged session there is nothing to count from.
            return;
        }
        foreach (HealthMilestones::at($since, $calendar->days->endOf($date)) as $state) {
            if ($state->reached) {
                // add() keeps the first time, so what was reached before is not changed.
                $this->milestones->add(new ReachedMilestone($userId, $state->milestone, $state->reachedAt));
            }
        }
    }
}
