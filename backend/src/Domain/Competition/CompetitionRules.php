<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Competition;

use InvalidArgumentException;

/**
 * Tunable numbers of competition scoring (SRS FR-PLAN-07).
 */
final readonly class CompetitionRules
{
    /**
     * 100.0 sessions a day. A higher minimum would replace practically every real baseline: a
     * baseline starts at 80.0 at most (onboarding, SRS FR-ACC-06) and can rise above that only
     * through the capped weekly updates (SRS FR-PLAN-06). A huge minimum would also push every
     * window past the bound Reduction compares in.
     */
    public const int MAX_SCORING_MINIMUM_TENTHS = 1000;

    public function __construct(
        /**
         * Lowest baseline (in tenths of a session per day) a competition scores against.
         * Near-zero baselines would otherwise turn a single session into a huge negative reduction.
         */
        public int $minScoringBaselineTenths = 10,
    ) {
        // Zero would allow an expected total of zero, which makes the reduction a division by zero.
        if ($minScoringBaselineTenths < 1) {
            throw new InvalidArgumentException('The scoring minimum of the baseline must be at least 0.1.');
        }
        if ($minScoringBaselineTenths > self::MAX_SCORING_MINIMUM_TENTHS) {
            throw new InvalidArgumentException(
                'The scoring minimum of the baseline cannot be above ' . self::MAX_SCORING_MINIMUM_TENTHS . ' tenths.',
            );
        }
    }
}
