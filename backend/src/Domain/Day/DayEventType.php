<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Day;

/**
 * What a finalized day gives the user to see and hear (SRS FR-GAME-06).
 *
 * The values are sent to the clients, which map each one to an animation and a sound, so they must
 * stay stable. There is deliberately no case for going over the limit or for a lost under-limit
 * streak: honest logging never triggers negative feedback (SRS FR-LOG-06). A day covered by a
 * streak freeze is sent as freeze_used alone, although it also ends the under-limit streak.
 */
enum DayEventType: string
{
    /** No value. */
    case CheckedIn = 'checked_in';

    /** Value: the new under-limit streak length. */
    case UnderLimitDay = 'under_limit_day';

    /** No value. */
    case SmokeFreeDay = 'smoke_free_day';

    /** Value: the new check-in streak length. */
    case StreakExtended = 'streak_extended';

    /** Value: the check-in streak milestone reached for the first time, in days. */
    case StreakMilestone = 'streak_milestone';

    /** Value: the freezes held after earning this one. */
    case FreezeEarned = 'freeze_earned';

    /** Value: the freezes left after using one. */
    case FreezeUsed = 'freeze_used';

    /** Value: the length the check-in streak had before it broke. */
    case StreakBroken = 'streak_broken';

    /** Value: the level reached. One event per level, lowest first. */
    case LevelUp = 'level_up';
}
