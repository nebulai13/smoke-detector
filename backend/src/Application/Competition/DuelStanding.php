<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

/**
 * Who is ahead in a running duel, as the user sees it against the opponent (FR-FRND-03, HLD §4.8
 * "What the opponent sees"): while the duel runs this replaces the opponent's percentage, because a
 * figure that moves every day could be calculated back to each day's share of the baseline.
 *
 * Decided as the winner is (FR-COMP-03) over the days that count so far: the exact reduction, then
 * smoke-free days, then check-ins.
 */
enum DuelStanding: string
{
    case Ahead = 'ahead';
    case Level = 'level';
    case Behind = 'behind';

    /**
     * @param int $comparison As CompetitionScoreComparator::compare() of the user against the opponent.
     */
    public static function of(int $comparison): self
    {
        return match ($comparison <=> 0) {
            1 => self::Ahead,
            0 => self::Level,
            default => self::Behind,
        };
    }
}
