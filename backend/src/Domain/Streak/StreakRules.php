<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Streak;

use InvalidArgumentException;

/**
 * Tunable numbers of streaks and streak freezes (SRS FR-GAME-04, Appendix A).
 */
final readonly class StreakRules
{
    /**
     * @param list<int> $milestoneThresholds Check-in streak lengths that are celebrated, ascending.
     */
    public function __construct(
        /** A freeze is earned each time the check-in streak reaches a multiple of this many days. */
        public int $freezeInterval = 7,
        /** A freeze earned while already holding this many is lost. Zero switches freezes off. */
        public int $maxFreezesHeld = 2,
        public array $milestoneThresholds = [7, 30, 100, 365],
    ) {
        if ($freezeInterval < 1) {
            throw new InvalidArgumentException('The freeze interval must be at least 1 day.');
        }
        if ($maxFreezesHeld < 0) {
            throw new InvalidArgumentException('The maximum number of freezes held cannot be negative.');
        }

        $previous = 0;
        foreach ($milestoneThresholds as $threshold) {
            // Strictly ascending from 1 rules out zero, negatives and duplicates in one check.
            if ($threshold <= $previous) {
                throw new InvalidArgumentException('Milestone thresholds must be positive and strictly ascending.');
            }
            $previous = $threshold;
        }
    }
}
