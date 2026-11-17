<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Streak;

/**
 * What can happen to a user's streaks when a day is finalized.
 *
 * The values are stored and sent to the clients, so they must stay stable.
 */
enum StreakEventType: string
{
    /** Value: the new check-in streak length. */
    case CheckInStreakExtended = 'check_in_streak_extended';

    /** Value: the threshold reached. Reported once per user, for a new personal best only. */
    case CheckInStreakMilestoneReached = 'check_in_streak_milestone_reached';

    /** Value: the length the check-in streak had before it broke. */
    case CheckInStreakBroken = 'check_in_streak_broken';

    /** Value: the new under-limit streak length. */
    case UnderLimitStreakExtended = 'under_limit_streak_extended';

    /**
     * Reported on a checked-in day over the limit and on every day without a check-in, frozen or not.
     * Value: the length the under-limit streak had before it broke.
     */
    case UnderLimitStreakBroken = 'under_limit_streak_broken';

    /** Value: the freezes held after earning this one. */
    case FreezeEarned = 'freeze_earned';

    /** A freeze was due but the user already held the maximum. Value: the freezes held (unchanged). */
    case FreezeLost = 'freeze_lost';

    /**
     * The check-in streak was kept on a day without a check-in. The under-limit streak is not
     * protected: a running one is reported broken on the same day, after this event.
     * Value: the freezes left after using one.
     */
    case FreezeUsed = 'freeze_used';
}
