<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Xp;

use InvalidArgumentException;
use SmokeDetector\Domain\Day\DayOutcome;

/**
 * Maps events to XP awards using the rule table (SRS FR-GAME-01, Appendix A).
 *
 * It only answers "how much is this event worth". Whether an event happened, and whether it was
 * already paid, is decided by the callers (day finalization, streaks, duels, leagues, reactions).
 */
final readonly class XpCalculator
{
    public function __construct(private XpRules $rules)
    {
    }

    /**
     * Day XP is for checked-in days only, so skipping the check-in is never worth more than being honest.
     *
     * @return list<XpAward>
     */
    public function forDay(DayOutcome $day): array
    {
        if (!$day->checkedIn) {
            return [];
        }

        $awards = [self::award(XpSource::CheckIn, $this->rules->checkIn)];
        if ($day->isUnderLimit()) {
            $awards[] = self::award(XpSource::UnderLimit, $this->rules->underLimit);
        }
        if ($day->isSmokeFree()) {
            $awards[] = self::award(XpSource::SmokeFree, $this->rules->smokeFree);
        }

        return array_values(array_filter($awards));
    }

    /**
     * The award for a check-in streak that has just reached $streakLength days, or null when that
     * length is not a milestone. The caller pays it only for a new personal best.
     */
    public function forStreakMilestone(int $streakLength): ?XpAward
    {
        if ($streakLength < 0) {
            throw new InvalidArgumentException('A streak length cannot be negative.');
        }

        return self::award(
            XpSource::StreakMilestone,
            $this->rules->streakMilestones[$streakLength] ?? 0,
            $streakLength,
        );
    }

    public function forDuel(DuelResult $result): ?XpAward
    {
        return self::award(XpSource::Duel, match ($result) {
            DuelResult::Won => $this->rules->duelWon,
            DuelResult::Draw => $this->rules->duelDraw,
            DuelResult::Lost => $this->rules->duelLost,
            DuelResult::Forfeited => $this->rules->duelForfeited,
        });
    }

    /**
     * @param int $rank         Final rank in the league, 1 is the best.
     * @param int $participants Number of users ranked in that league, including this one.
     */
    public function forLeague(int $rank, LeaguePeriod $period, int $participants): ?XpAward
    {
        if ($rank < 1) {
            throw new InvalidArgumentException('A league rank starts at 1.');
        }
        if ($participants < $rank) {
            throw new InvalidArgumentException('A league rank cannot be larger than the number of participants.');
        }
        if ($participants < $this->rules->leagueMinParticipants) {
            return null;
        }

        $multiplier = match ($period) {
            LeaguePeriod::Week => $this->rules->leagueWeekMultiplier,
            LeaguePeriod::Month => $this->rules->leagueMonthMultiplier,
            LeaguePeriod::Quarter => $this->rules->leagueQuarterMultiplier,
            LeaguePeriod::HalfYear => $this->rules->leagueHalfYearMultiplier,
        };

        return self::award(XpSource::League, ($this->rules->leagueRankXp[$rank] ?? 0) * $multiplier, $rank);
    }

    /**
     * The award for sending one more reaction today.
     *
     * A reaction that would cross the daily cap still earns the part that fits, so the cap is the
     * exact daily maximum whatever the XP per reaction is.
     *
     * @param int $reactionXpAlreadyEarnedToday Reaction XP the user has earned so far on this local day.
     */
    public function forReaction(int $reactionXpAlreadyEarnedToday): ?XpAward
    {
        if ($reactionXpAlreadyEarnedToday < 0) {
            throw new InvalidArgumentException('The reaction XP earned today cannot be negative.');
        }

        // Already above the cap is possible after the cap was lowered. Then there is simply nothing left.
        $remaining = max(0, $this->rules->reactionDailyCap - $reactionXpAlreadyEarnedToday);

        return self::award(XpSource::Reaction, min($this->rules->reaction, $remaining));
    }

    /**
     * @param list<XpAward> $awards
     */
    public static function total(array $awards): int
    {
        $total = 0;
        foreach ($awards as $award) {
            // Past PHP_INT_MAX the sum would turn into a float and single XP points would be lost.
            if ($award->amount > PHP_INT_MAX - $total) {
                throw new InvalidArgumentException('The XP awards are too large to be added up.');
            }
            $total += $award->amount;
        }

        return $total;
    }

    /**
     * A reward that is switched off in the rule table (0 XP) produces no award at all.
     */
    private static function award(XpSource $source, int $amount, ?int $detail = null): ?XpAward
    {
        return $amount === 0 ? null : new XpAward($source, $amount, $detail);
    }
}
