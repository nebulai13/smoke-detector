<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Plan;

use InvalidArgumentException;

/**
 * Tunable numbers of the daily limit (SRS FR-PLAN-01): the share of the baseline each taper speed allows.
 *
 * Whole percentages keep the limit an exact integer calculation.
 */
final readonly class PlanRules
{
    public function __construct(
        public int $gentleLimitPercent = 90,
        public int $normalLimitPercent = 80,
        public int $fastLimitPercent = 70,
    ) {
        $percentages = [
            'gentle' => $gentleLimitPercent,
            'normal' => $normalLimitPercent,
            'fast' => $fastLimitPercent,
        ];
        foreach ($percentages as $speed => $percent) {
            // 0 % would pin every limit to zero whatever the baseline, and above 100 % is not a reduction.
            if ($percent < 1 || $percent > 100) {
                throw new InvalidArgumentException(
                    "The limit percentage of the {$speed} taper speed must be between 1 and 100, got {$percent}.",
                );
            }
        }

        // The speed names are a promise to the user, so a faster plan may never allow more than a slower one.
        if ($normalLimitPercent > $gentleLimitPercent || $fastLimitPercent > $normalLimitPercent) {
            throw new InvalidArgumentException(
                'A faster taper speed cannot allow a larger share of the baseline than a slower one.',
            );
        }
    }

    /**
     * Share of the baseline that is allowed per day at the given speed, in percent.
     */
    public function limitPercent(TaperSpeed $speed): int
    {
        return match ($speed) {
            TaperSpeed::Gentle => $this->gentleLimitPercent,
            TaperSpeed::Normal => $this->normalLimitPercent,
            TaperSpeed::Fast => $this->fastLimitPercent,
        };
    }
}
