<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Plan;

/**
 * Result of one weekly baseline update, including the inputs kept for the baseline history.
 */
final readonly class BaselineUpdate
{
    public function __construct(
        public Baseline $value,
        public BaselineSource $source,
        /** Mean sessions/day over the checked-in days of the last week. Null without such days. */
        public ?float $weekMean,
        /** Mean sessions/day over the checked-in days of the last 28 days. Null without such days. */
        public ?float $monthMean,
        public int $weekDays,
        public int $monthDays,
    ) {
    }
}
