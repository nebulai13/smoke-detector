<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Competition;

use InvalidArgumentException;

/**
 * One participant's result over a competition window: the reduction and the tie-breaker inputs of
 * SRS FR-COMP-03, all taken from the same days.
 */
final readonly class CompetitionScore
{
    public function __construct(
        public Reduction $reduction,
        /** Checked-in days with a count of 0. */
        public int $smokeFreeDays,
        public int $checkIns,
        /** Days of the window the participant is rated over, with or without a check-in. */
        public int $countedDays,
    ) {
        if ($countedDays < 1) {
            throw new InvalidArgumentException('A score needs at least one counted day.');
        }
        if ($checkIns < 0 || $checkIns > $countedDays) {
            throw new InvalidArgumentException('Check-ins must be between 0 and the number of counted days.');
        }
        // Only a checked-in day can be smoke-free (SRS §1.4, Check-in).
        if ($smokeFreeDays < 0 || $smokeFreeDays > $checkIns) {
            throw new InvalidArgumentException('Smoke-free days must be between 0 and the number of check-ins.');
        }
    }
}
