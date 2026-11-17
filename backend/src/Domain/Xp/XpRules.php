<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Xp;

use InvalidArgumentException;

/**
 * The XP rule table (SRS FR-GAME-01). The defaults are the values of SRS Appendix A.
 *
 * A value of 0 switches a reward off. Negative values are rejected, because XP never punishes.
 */
final readonly class XpRules
{
    /**
     * Upper bound of every number here. It keeps each award (at most MAX_VALUE × MAX_VALUE for a
     * league rank) and the XP of one day inside a 64-bit integer, and it is far above any value
     * that makes sense for the product.
     */
    public const int MAX_VALUE = 1_000_000;

    /**
     * @param array<int, int> $streakMilestones Check-in streak length => XP.
     * @param array<int, int> $leagueRankXp     Final rank => XP for a one-week league, before the period multiplier.
     */
    public function __construct(
        public int $checkIn = 10,
        public int $underLimit = 20,
        /** Paid on top of the check-in and under-limit XP. */
        public int $smokeFree = 40,
        public array $streakMilestones = [7 => 50, 30 => 150, 100 => 500, 365 => 1500],
        public int $duelWon = 100,
        public int $duelDraw = 50,
        /** Lost, but completed. */
        public int $duelLost = 20,
        public int $duelForfeited = 0,
        public array $leagueRankXp = [1 => 150, 2 => 100, 3 => 50],
        /** A league with fewer participants pays no XP, so a rank cannot be won by default. */
        public int $leagueMinParticipants = 3,
        public int $leagueWeekMultiplier = 1,
        public int $leagueMonthMultiplier = 2,
        public int $leagueQuarterMultiplier = 3,
        public int $leagueHalfYearMultiplier = 4,
        public int $reaction = 2,
        /** Most XP a user can earn from sending reactions on one day. */
        public int $reactionDailyCap = 10,
    ) {
        $amounts = [
            'checkIn' => $checkIn,
            'underLimit' => $underLimit,
            'smokeFree' => $smokeFree,
            'duelWon' => $duelWon,
            'duelDraw' => $duelDraw,
            'duelLost' => $duelLost,
            'duelForfeited' => $duelForfeited,
            'leagueMinParticipants' => $leagueMinParticipants,
            'leagueWeekMultiplier' => $leagueWeekMultiplier,
            'leagueMonthMultiplier' => $leagueMonthMultiplier,
            'leagueQuarterMultiplier' => $leagueQuarterMultiplier,
            'leagueHalfYearMultiplier' => $leagueHalfYearMultiplier,
            'reaction' => $reaction,
            'reactionDailyCap' => $reactionDailyCap,
        ];
        foreach ($amounts as $name => $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException("XP rule {$name} cannot be negative.");
            }
            if ($amount > self::MAX_VALUE) {
                throw new InvalidArgumentException("XP rule {$name} cannot be above " . self::MAX_VALUE . '.');
            }
        }

        self::assertTable('streakMilestones', $streakMilestones);
        self::assertTable('leagueRankXp', $leagueRankXp);
    }

    /**
     * @param array<int, int> $table
     */
    private static function assertTable(string $name, array $table): void
    {
        foreach ($table as $key => $xp) {
            if ($key < 1) {
                throw new InvalidArgumentException("XP rule {$name} needs keys of 1 or more, got {$key}.");
            }
            if ($xp < 0) {
                throw new InvalidArgumentException("XP rule {$name} cannot hold negative XP.");
            }
            if ($xp > self::MAX_VALUE) {
                throw new InvalidArgumentException(
                    "XP rule {$name} cannot hold more than " . self::MAX_VALUE . ' XP.',
                );
            }
        }
    }
}
