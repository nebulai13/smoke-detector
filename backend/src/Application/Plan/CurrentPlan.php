<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Baseline;

/**
 * A user's plan together with what follows from it today.
 */
final readonly class CurrentPlan
{
    public function __construct(
        /** As the user chose it last: goal and taper speed may count from tomorrow only. */
        public Plan $plan,
        /** The current baseline: the entry of the baseline history that is valid today. */
        public Baseline $baseline,
        /** Sessions allowed today, from the baseline and the plan terms valid today (FR-PLAN-01). */
        public int $dailyLimit,
        /** While the newest choice of goal or taper speed does not count yet: the day it will. */
        public ?LocalDate $pendingFrom = null,
    ) {
    }
}
