<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Recovery;

use DateTimeImmutable;
use SmokeDetector\Application\Day\Calendar;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Feed\PublishToFeed;
use SmokeDetector\Application\Logging\SmokeLogs;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Recovery\HealthMilestones;

/**
 * Stores the health milestones a user has reached for good (HLD §4.11 "Milestones for friends",
 * SRS FR-GAME-09, FR-FRND-03), counting confirmed time only (HLD §4.11 "Only confirmed time counts"):
 * from the last session logged for a final day or a day before it, or from the end of the last day
 * up to it without a check-in if that is later, to the end of that day. Without a logged session, a
 * day the plan names as the last session before joining counts from the start of the first day of
 * the plan: the days before it are the user's word, the days of the plan are confirmed by check-ins.
 *
 * A day without a check-in is not a day without a session (FR-LOG-03): nobody said it was
 * smoke-free. Counted from the last session alone, an account that logs one session and is never
 * opened again would collect every milestone, as the cron job makes its days final one by one. A
 * final day that is itself not checked in therefore reaches nothing.
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
        private DayRecords $days,
        private PublishToFeed $feed,
    ) {
    }

    /**
     * After a day of the user of the calendar has become final.
     *
     * @param DateTimeImmutable $now The time of the step: of the feed entry, not of the milestone.
     */
    public function dayFinalized(Calendar $calendar, LocalDate $date, DateTimeImmutable $now): void
    {
        $userId = $calendar->plan->userId;
        $since = $this->logs->lastSessionUntil($userId, $date);
        if ($since === null && $calendar->plan->lastSessionBefore !== null) {
            $since = $calendar->days->startOf($calendar->plan->startDate);
        }
        if ($since === null) {
            // Without a logged session or a named day nothing says that the user does not smoke.
            return;
        }
        $missed = $this->days->lastMissedUntil($userId, $date);
        if ($missed !== null) {
            // Only the time after the last day nobody confirmed counts.
            $since = max($since, $calendar->days->endOf($missed));
        }
        foreach (HealthMilestones::at($since, $calendar->days->endOf($date)) as $state) {
            // add() keeps the first time, so what was reached before is not changed, and is not news
            // for the feed again (HLD §4.12).
            if ($state->reached && $this->milestones->add(new ReachedMilestone($userId, $state->milestone, $state->reachedAt))) {
                $this->feed->milestoneReached($userId, $state->milestone, $now);
            }
        }
    }
}
