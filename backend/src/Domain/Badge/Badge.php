<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Badge;

/**
 * The badges of SRS FR-GAME-08 and Appendix B.
 *
 * The values are stored and sent to the clients, which have a picture and a text for each, so they
 * must stay stable. The order of the cases is the order in which badges are listed.
 */
enum Badge: string
{
    case FirstCheckIn = 'first_check_in';
    case FirstSmokeFreeDay = 'first_smoke_free_day';
    case CheckInStreak7 = 'check_in_streak_7';
    case CheckInStreak30 = 'check_in_streak_30';
    case CheckInStreak100 = 'check_in_streak_100';
    case CheckInStreak365 = 'check_in_streak_365';
    case UnderLimitStreak7 = 'under_limit_streak_7';
    case UnderLimitStreak30 = 'under_limit_streak_30';
    case LimitHalved = 'limit_halved';
    case TargetReached = 'target_reached';
    case FirstDuelWon = 'first_duel_won';
    case FiveDuelsWon = 'five_duels_won';
    case LeagueChampionWeek = 'league_champion_week';
    case LeagueChampionMonth = 'league_champion_month';
    case LeagueChampionQuarter = 'league_champion_quarter';
    case LeagueChampionHalfYear = 'league_champion_half_year';
    case Cheerleader = 'cheerleader';
}
