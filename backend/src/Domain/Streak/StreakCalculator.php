<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Streak;

use InvalidArgumentException;
use SmokeDetector\Domain\Day\DayOutcome;

/**
 * Advances a user's streaks by one finalized day (SRS FR-GAME-02 to FR-GAME-04).
 *
 * The check-in streak survives a day without a check-in when a freeze is held. The under-limit
 * streak survives only a checked-in day at or under the limit: a freeze never protects it.
 *
 * Days must be fed in calendar order, each exactly once. The caller owns that ordering.
 */
final readonly class StreakCalculator
{
    public function __construct(private StreakRules $rules)
    {
    }

    public function advance(StreakState $state, DayOutcome $day): StreakAdvance
    {
        if ($state->freezesHeld > $this->rules->maxFreezesHeld) {
            throw new InvalidArgumentException(
                "At most {$this->rules->maxFreezesHeld} streak freezes can be held.",
            );
        }

        return $day->checkedIn
            ? $this->checkedInDay($state, $day->isUnderLimit())
            : $this->missedDay($state);
    }

    private function checkedInDay(StreakState $state, bool $underLimit): StreakAdvance
    {
        // One more day would silently turn the counter into a float. The under-limit streak is
        // never longer than the check-in streak (StreakState), so this one check covers both.
        if ($state->checkInCurrent === PHP_INT_MAX) {
            throw new InvalidArgumentException('The check-in streak is too long to be extended.');
        }

        $checkIn = $state->checkInCurrent + 1;
        $events = [new StreakEvent(StreakEventType::CheckInStreakExtended, $checkIn)];

        foreach ($this->rules->milestoneThresholds as $threshold) {
            // Comparing with the personal best, not the previous length, keeps a rebuilt streak
            // from being paid for the same milestone twice (SRS Appendix A).
            if ($state->checkInLongest < $threshold && $threshold <= $checkIn) {
                $events[] = new StreakEvent(StreakEventType::CheckInStreakMilestoneReached, $threshold);
            }
        }

        $freezes = $state->freezesHeld;
        if ($this->rules->maxFreezesHeld > 0 && $checkIn % $this->rules->freezeInterval === 0) {
            if ($freezes < $this->rules->maxFreezesHeld) {
                $freezes++;
                $events[] = new StreakEvent(StreakEventType::FreezeEarned, $freezes);
            } else {
                $events[] = new StreakEvent(StreakEventType::FreezeLost, $freezes);
            }
        }

        $underLimitStreak = 0;
        if ($underLimit) {
            $underLimitStreak = $state->underLimitCurrent + 1;
            $events[] = new StreakEvent(StreakEventType::UnderLimitStreakExtended, $underLimitStreak);
        } elseif ($state->underLimitCurrent > 0) {
            $events[] = new StreakEvent(StreakEventType::UnderLimitStreakBroken, $state->underLimitCurrent);
        }

        return new StreakAdvance(
            new StreakState(
                $checkIn,
                max($state->checkInLongest, $checkIn),
                $underLimitStreak,
                max($state->underLimitLongest, $underLimitStreak),
                $freezes,
            ),
            $events,
        );
    }

    private function missedDay(StreakState $state): StreakAdvance
    {
        // The under-limit streak is never longer than the check-in streak, so there is nothing to
        // protect or to break here. Keeping the freeze avoids wasting it on an empty streak.
        if ($state->checkInCurrent === 0) {
            return new StreakAdvance($state, []);
        }

        if ($state->freezesHeld > 0) {
            // A freeze protects the check-in streak only (SRS FR-GAME-04).
            $checkIn = $state->checkInCurrent;
            $freezes = $state->freezesHeld - 1;
            $events = [new StreakEvent(StreakEventType::FreezeUsed, $freezes)];
        } else {
            $checkIn = 0;
            $freezes = 0;
            $events = [new StreakEvent(StreakEventType::CheckInStreakBroken, $state->checkInCurrent)];
        }

        // An unconfirmed day is never an under-limit day, so this streak ends with or without a
        // freeze (SRS FR-GAME-03). Otherwise skipping the check-in on a heavy day would save a streak
        // that the honest check-in loses. What was logged on the day plays no role.
        if ($state->underLimitCurrent > 0) {
            $events[] = new StreakEvent(StreakEventType::UnderLimitStreakBroken, $state->underLimitCurrent);
        }

        return new StreakAdvance(
            new StreakState($checkIn, $state->checkInLongest, 0, $state->underLimitLongest, $freezes),
            $events,
        );
    }
}
