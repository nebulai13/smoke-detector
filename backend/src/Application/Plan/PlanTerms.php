<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;

/**
 * One entry of a user's plan history (HLD §4.3 "plan_history"): what the plan says from a local
 * day on. The limit of a day is calculated with the terms valid on it, so a change counts from the
 * next day and every past day can be recalculated (NFR-04).
 */
final readonly class PlanTerms
{
    public function __construct(
        public UserId $userId,
        public LocalDate $validFrom,
        public Goal $goal,
        public TaperSpeed $taperSpeed,
        /** The version of the rule table the plan was made under. */
        public string $rulesVersion,
    ) {
    }
}
