<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;

/**
 * How a duel was decided (HLD §4.8 "Score"): when, who won, and both sides as they were scored,
 * with the version of the rules (NFR-04).
 */
final readonly class DuelDecision
{
    public function __construct(
        public DateTimeImmutable $decidedAt,
        public DuelWinner $winner,
        /** Null once the challenger's account is deleted: the side goes with it. */
        public ?SideResult $challenger,
        /** Null once the opponent's account is deleted. */
        public ?SideResult $opponent,
        public string $rulesVersion,
    ) {
    }

    /**
     * @param bool $challenger Whether the side of the challenger is meant.
     */
    public function sideOf(bool $challenger): ?SideResult
    {
        return $challenger ? $this->challenger : $this->opponent;
    }
}
