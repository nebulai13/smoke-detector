<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Plan;

/**
 * How quickly the user wants to come down (SRS FR-PLAN-01, chosen in onboarding).
 *
 * How far below the baseline each speed puts the daily limit is a tunable number, so it lives in PlanRules.
 */
enum TaperSpeed: string
{
    case Gentle = 'gentle';
    case Normal = 'normal';
    case Fast = 'fast';
}
