<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

/**
 * What one run of `competitions:finalize` did. Counts only: nothing here says whose duels they were.
 */
final readonly class DuelReport
{
    public function __construct(
        /** Proposals marked as expired. */
        public int $expired,
        /** Duels decided. */
        public int $decided,
        /** Items that failed, were rolled back and logged. */
        public int $failed,
    ) {
    }
}
