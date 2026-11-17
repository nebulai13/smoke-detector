<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Xp;

/**
 * The calendar-aligned league periods (SRS FR-COMP-06). Longer periods pay more XP.
 */
enum LeaguePeriod: string
{
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case HalfYear = 'half_year';
}
