<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Plan;

/**
 * Why a baseline history entry has the value it has.
 */
enum BaselineSource: string
{
    case Onboarding = 'onboarding';
    case Weekly = 'weekly';
    case UnchangedLowData = 'unchanged_low_data';
    case Capped = 'capped';
}
