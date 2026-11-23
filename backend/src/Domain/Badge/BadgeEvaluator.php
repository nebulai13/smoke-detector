<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Badge;

use InvalidArgumentException;
use SmokeDetector\Domain\Day\DayOutcome;
use SmokeDetector\Domain\Streak\StreakState;
use SmokeDetector\Domain\Xp\LeaguePeriod;

/**
 * Which badges a set of facts justifies (SRS FR-GAME-08, Appendix B).
 *
 * It names a badge every time its condition holds, not only the first time: the storage keeps the
 * first award. So an award that was missed once is made up for later, and none is given twice.
 * Every list is in the order of the Badge cases.
 */
final class BadgeEvaluator
{
    // The thresholds are from SRS Appendix B. They are part of what a badge is (its key names them),
    // which is why they are constants here and not in the versioned rule table.

    /** Check-in streak lengths, in days, with their badge. */
    public const array CHECK_IN_STREAKS = [
        7 => Badge::CheckInStreak7,
        30 => Badge::CheckInStreak30,
        100 => Badge::CheckInStreak100,
        365 => Badge::CheckInStreak365,
    ];

    /** Under-limit streak lengths, in days, with their badge. */
    public const array UNDER_LIMIT_STREAKS = [
        7 => Badge::UnderLimitStreak7,
        30 => Badge::UnderLimitStreak30,
    ];

    public const int FIRST_DUEL_WON = 1;
    public const int FIVE_DUELS_WON = 5;
    public const int CHEERLEADER_REACTIONS = 50;

    /**
     * The badges justified by a day that has just become final.
     *
     * The streak badges follow the longest streaks, not the current ones, so a streak that broke
     * today still justifies what it reached before.
     *
     * @param StreakState $after      The streaks after this day.
     * @param int         $firstLimit The daily limit on the first day of the plan.
     * @param int         $target     The daily limit the plan ends at (0 to quit).
     *
     * @return list<Badge>
     */
    public static function forDay(DayOutcome $day, StreakState $after, int $firstLimit, int $target): array
    {
        if ($firstLimit < 0) {
            throw new InvalidArgumentException('A first limit cannot be negative.');
        }
        if ($target < 0) {
            throw new InvalidArgumentException('A target cannot be negative.');
        }

        $badges = [];
        if ($day->checkedIn) {
            $badges[] = Badge::FirstCheckIn;
        }
        if ($day->isSmokeFree()) {
            $badges[] = Badge::FirstSmokeFreeDay;
        }
        $badges = [
            ...$badges,
            ...self::reached(self::CHECK_IN_STREAKS, $after->checkInLongest),
            ...self::reached(self::UNDER_LIMIT_STREAKS, $after->underLimitLongest),
        ];
        if ($day->isUnderLimit()) {
            // A plan that began at 0 has nothing to halve.
            if ($firstLimit >= 1 && $day->limit * 2 <= $firstLimit) {
                $badges[] = Badge::LimitHalved;
            }
            // The limit itself must have arrived at the target: one good day on the way down is not it.
            // And it must have come down to it: a plan that began at the target, or a target set
            // above the limit later, has reached nothing.
            if ($day->limit === $target && $target < $firstLimit) {
                $badges[] = Badge::TargetReached;
            }
        }

        return $badges;
    }

    /**
     * @return list<Badge>
     */
    public static function forDuelsWon(int $won): array
    {
        if ($won < 0) {
            throw new InvalidArgumentException('The number of duels won cannot be negative.');
        }

        return self::reached(
            [self::FIRST_DUEL_WON => Badge::FirstDuelWon, self::FIVE_DUELS_WON => Badge::FiveDuelsWon],
            $won,
        );
    }

    /**
     * The champion badge for rank 1, with the same minimum of participants as the XP of a rank:
     * being first of two, or alone, is no title.
     *
     * @param int $rank         Final rank in the league, 1 is the best.
     * @param int $participants Number of users ranked in that league, including this one.
     *
     * @return list<Badge>
     */
    public static function forLeague(LeaguePeriod $period, int $rank, int $participants, int $minParticipants): array
    {
        if ($rank < 1) {
            throw new InvalidArgumentException('A league rank starts at 1.');
        }
        if ($participants < $rank) {
            throw new InvalidArgumentException('A league rank cannot be larger than the number of participants.');
        }
        if ($rank !== 1 || $participants < $minParticipants) {
            return [];
        }

        return [match ($period) {
            LeaguePeriod::Week => Badge::LeagueChampionWeek,
            LeaguePeriod::Month => Badge::LeagueChampionMonth,
            LeaguePeriod::Quarter => Badge::LeagueChampionQuarter,
            LeaguePeriod::HalfYear => Badge::LeagueChampionHalfYear,
        }];
    }

    /**
     * @return list<Badge>
     */
    public static function forReactionsSent(int $sent): array
    {
        if ($sent < 0) {
            throw new InvalidArgumentException('The number of reactions sent cannot be negative.');
        }

        return self::reached([self::CHEERLEADER_REACTIONS => Badge::Cheerleader], $sent);
    }

    /**
     * @param array<int, Badge> $thresholds In ascending order.
     *
     * @return list<Badge>
     */
    private static function reached(array $thresholds, int $value): array
    {
        $badges = [];
        foreach ($thresholds as $threshold => $badge) {
            if ($value >= $threshold) {
                $badges[] = $badge;
            }
        }

        return $badges;
    }
}
