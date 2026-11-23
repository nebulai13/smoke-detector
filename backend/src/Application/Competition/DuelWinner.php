<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use SmokeDetector\Domain\Xp\DuelResult;

/**
 * Who won a decided duel (HLD §4.8 "Score"). Stored in `duels.winner`.
 */
enum DuelWinner: string
{
    case Challenger = 'challenger';
    case Opponent = 'opponent';
    case Draw = 'draw';

    /**
     * The winner of the comparison of the challenger's score with the opponent's.
     *
     * @param int $comparison CompetitionScoreComparator::compare(challenger, opponent): 1, -1 or 0.
     */
    public static function of(int $comparison): self
    {
        return match ($comparison) {
            1 => self::Challenger,
            -1 => self::Opponent,
            default => self::Draw,
        };
    }

    /**
     * How the duel ended for one of the two.
     *
     * @param bool $challenger Whether that one is the challenger.
     */
    public function resultFor(bool $challenger): DuelResult
    {
        if ($this === self::Draw) {
            return DuelResult::Draw;
        }

        return ($this === self::Challenger) === $challenger ? DuelResult::Won : DuelResult::Lost;
    }
}
