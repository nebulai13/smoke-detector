<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Plan;

use InvalidArgumentException;

/**
 * Derives the daily limit from the baseline (SRS FR-PLAN-01).
 */
final class ReductionPlan
{
    /**
     * floor(baseline × factor). In reduce mode the limit never goes below the target.
     */
    public static function dailyLimit(Baseline $baseline, TaperSpeed $speed, Goal $goal, PlanRules $rules): int
    {
        // A percentage is at most 100 (PlanRules). Past this bound the product below would silently
        // turn into a float, and the floor would no longer be exact.
        if ($baseline->tenths > intdiv(PHP_INT_MAX, 100)) {
            throw new InvalidArgumentException('The baseline is too large to derive a daily limit from.');
        }

        // tenths × percent is in thousandths of a session, so dividing by 1000 floors exactly.
        $limit = intdiv($baseline->tenths * $rules->limitPercent($speed), 1000);

        return max($limit, $goal->target);
    }
}
