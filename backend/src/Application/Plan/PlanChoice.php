<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;

/**
 * What a user chooses in PUT /me/plan (FR-ACC-06).
 */
final readonly class PlanChoice
{
    /** The largest target a request may name. A baseline never exceeds 999.9 (BaselineEntry). */
    public const int MAX_TARGET = 999;

    public function __construct(
        /**
         * Sessions per day the user has now. Needed for the first plan. Later it may only repeat the
         * value of the onboarding, or be left out (null).
         */
        public ?int $startingBaseline,
        public Goal $goal,
        public TaperSpeed $taperSpeed,
        public ProductType $defaultProduct,
    ) {
    }
}
