<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use SmokeDetector\Domain\Competition\CompetitionScore;
use SmokeDetector\Domain\Competition\Reduction;

/**
 * One side of a duel as both of the two see it (FR-FRND-03, HLD §4.8 "What the opponent sees"):
 * the reduction in percent and the tie-breakers. Deliberately no baseline and no totals, so a
 * responder cannot let them out.
 */
final readonly class SideStanding
{
    /** Percentages are shown with one decimal and never compared. */
    private const int DECIMALS = 1;

    public function __construct(
        /** One decimal. Negative when more was smoked than the baseline. */
        public float $reductionPercent,
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
     * A reduction as it is shown.
     */
    public static function percent(Reduction $reduction): float
    {
        return $reduction->toPercent(self::DECIMALS);
    }
}
