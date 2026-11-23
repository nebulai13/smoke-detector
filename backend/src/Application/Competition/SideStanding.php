<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use SmokeDetector\Domain\Competition\CompetitionScore;
use SmokeDetector\Domain\Competition\Reduction;

/**
 * One side of a duel as the two see it (FR-FRND-03, HLD §4.8 "What the opponent sees"): the
 * reduction in whole percent and the tie-breakers. Deliberately no baseline and no totals, and no
 * percentage for the opponent of a running duel, so a responder cannot let them out.
 */
final readonly class SideStanding
{
    public function __construct(
        /**
         * Whole percent, rounded half away from zero: an exact figure could be calculated back to
         * the baseline and the counts. Negative when more was smoked than the baseline. Null for
         * the opponent of a running duel: a figure that moves every day could be calculated back.
         */
        public ?int $reductionPercent,
        public int $smokeFreeDays,
        public int $checkIns,
        public int $countedDays,
    ) {
    }

    /**
     * The side over the days that count so far.
     */
    public static function ofScore(CompetitionScore $score): self
    {
        return new self(self::percent($score->reduction), $score->smokeFreeDays, $score->checkIns, $score->countedDays);
    }

    /**
     * The opponent's side over the days that count so far, without its percentage.
     */
    public static function ofOpponentScore(CompetitionScore $score): self
    {
        return new self(null, $score->smokeFreeDays, $score->checkIns, $score->countedDays);
    }

    /**
     * The side of a decided duel, as it was stored. Every day of the duel counted.
     */
    public static function ofResult(SideResult $result, int $periodDays): self
    {
        return new self(
            self::percent(Reduction::fromTotals($result->actualTenths, $result->expectedTenths)),
            $result->smokeFreeDays,
            $result->checkIns,
            $periodDays,
        );
    }

    /**
     * A reduction as it is shown. Never compared: winners are decided on the exact reduction.
     */
    private static function percent(Reduction $reduction): int
    {
        return (int) $reduction->toPercent(0);
    }
}
