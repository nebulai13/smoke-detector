<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Domain\Plan\Baseline;

/**
 * A user's plan together with what follows from it today.
 */
final readonly class CurrentPlan
{
    public function __construct(
        public Plan $plan,
        /** The current baseline: the latest entry of the baseline history. */
        public Baseline $baseline,
        /** Sessions allowed per day (FR-PLAN-01). */
        public int $dailyLimit,
    ) {
    }
}
