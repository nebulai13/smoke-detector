<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Day;

use InvalidArgumentException;
use SmokeDetector\Domain\Level\LevelCalculator;
use SmokeDetector\Domain\Rules\RuleSet;
use SmokeDetector\Domain\Streak\StreakAdvance;
use SmokeDetector\Domain\Streak\StreakCalculator;
use SmokeDetector\Domain\Streak\StreakEventType;
use SmokeDetector\Domain\Xp\XpAward;
use SmokeDetector\Domain\Xp\XpCalculator;

/**
 * Turns one finalized day into its effect on the user: streaks, XP, level and celebration events
 * (HLD §5.2 step 2). It only combines the streak, XP and level rules; none of them is repeated here.
 *
 * Days must be fed in calendar order, each exactly once. Ordering and "exactly once" belong to the
 * caller, which runs one transaction per user-day.
 */
final readonly class DayFinalizer
{
    private StreakCalculator $streaks;
    private XpCalculator $xp;
    private LevelCalculator $levels;

    public function __construct(RuleSet $rules)
    {
        $this->streaks = new StreakCalculator($rules->streak);
        $this->xp = new XpCalculator($rules->xp);
        $this->levels = new LevelCalculator($rules->level);
    }

    public function finalize(UserProgress $before, DayOutcome $day): DayFinalization
    {
        $advance = $this->streaks->advance($before->streaks, $day);

        $awards = [...$this->xp->forDay($day), ...$this->milestoneAwards($advance)];
        $earned = XpCalculator::total($awards);
        // Past this point the sum would turn into a float and single XP points would be lost.
        if ($earned > PHP_INT_MAX - $before->totalXp) {
            throw new InvalidArgumentException('The XP total is too large to add the XP of this day.');
        }
        $totalXp = $before->totalXp + $earned;

        $events = [...self::dayEvents($day, $advance), ...self::streakEvents($advance)];
        // Level-ups come last: they are the consequence of everything that was just celebrated.
        foreach ($this->levels->levelsGained($before->totalXp, $totalXp) as $level) {
            $events[] = new DayEvent(DayEventType::LevelUp, $level);
        }

        return new DayFinalization(
            $advance->state,
            $awards,
            $totalXp,
            $this->levels->levelFor($before->totalXp),
            $this->levels->levelFor($totalXp),
            $events,
        );
    }

    /**
     * The streak rules already report a milestone only for a new personal best, so every reported
     * milestone is paid here without a second "first time" check.
     *
     * @return list<XpAward>
     */
    private function milestoneAwards(StreakAdvance $advance): array
    {
        $awards = [];
        foreach ($advance->events as $event) {
            if ($event->type !== StreakEventType::CheckInStreakMilestoneReached || $event->value === null) {
                continue;
            }
            $award = $this->xp->forStreakMilestone($event->value);
            if ($award !== null) {
                $awards[] = $award;
            }
        }

        return $awards;
    }

    /**
     * What kind of day it was, before anything about streaks.
     *
     * @return list<DayEvent>
     */
    private static function dayEvents(DayOutcome $day, StreakAdvance $advance): array
    {
        $events = [];
        if ($day->checkedIn) {
            $events[] = new DayEvent(DayEventType::CheckedIn);
        }
        if ($day->isUnderLimit()) {
            $events[] = new DayEvent(DayEventType::UnderLimitDay, $advance->state->underLimitCurrent);
        }
        if ($day->isSmokeFree()) {
            $events[] = new DayEvent(DayEventType::SmokeFreeDay);
        }

        return $events;
    }

    /**
     * @return list<DayEvent>
     */
    private static function streakEvents(StreakAdvance $advance): array
    {
        $events = [];
        foreach ($advance->events as $event) {
            $type = match ($event->type) {
                StreakEventType::CheckInStreakExtended => DayEventType::StreakExtended,
                StreakEventType::CheckInStreakMilestoneReached => DayEventType::StreakMilestone,
                StreakEventType::FreezeEarned => DayEventType::FreezeEarned,
                StreakEventType::FreezeUsed => DayEventType::FreezeUsed,
                StreakEventType::CheckInStreakBroken => DayEventType::StreakBroken,
                // The under-limit streak is shown through under_limit_day. Its end, and a freeze that
                // could not be held, would be negative feedback for an honest day (SRS FR-LOG-06).
                // The same holds for a frozen day, which also ends the under-limit streak: the client
                // gets freeze_used only, and the next under-limit day shows the streak starting at 1.
                StreakEventType::UnderLimitStreakExtended,
                StreakEventType::UnderLimitStreakBroken,
                StreakEventType::FreezeLost => null,
            };
            if ($type !== null) {
                $events[] = new DayEvent($type, $event->value);
            }
        }

        return $events;
    }
}
