<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;

/**
 * The reduction plan of a user as it is stored (HLD §4.3 "plans"). A user has one, from the
 * onboarding on. The baseline is not part of it: that has its own history (BaselineHistory).
 */
final readonly class Plan
{
    public function __construct(
        public UserId $userId,
        public Goal $goal,
        public TaperSpeed $taperSpeed,
        /** What the "+1" button logs unless the user picks something else. */
        public ProductType $defaultProduct,
        /** The user's local day of the onboarding. Never changes. */
        public LocalDate $startDate,
        /** The version of the rule table that was in force when the plan was made. */
        public string $rulesVersion,
    ) {
    }

    /**
     * What the user may change later (FR-ACC-06, FR-PLAN-03). Everything else stays as it is.
     */
    public function changedTo(Goal $goal, TaperSpeed $taperSpeed, ProductType $defaultProduct): self
    {
        return new self($this->userId, $goal, $taperSpeed, $defaultProduct, $this->startDate, $this->rulesVersion);
    }
}
