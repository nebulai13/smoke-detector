<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Xp;

/**
 * What an XP award was given for (SRS Appendix A).
 *
 * The values are stored with every XP event, so renaming one changes how old events are read.
 */
enum XpSource: string
{
    case CheckIn = 'check_in';
    case UnderLimit = 'under_limit';
    case SmokeFree = 'smoke_free';
    case StreakMilestone = 'streak_milestone';
    case Duel = 'duel';
    case League = 'league';
    case Reaction = 'reaction';
}
