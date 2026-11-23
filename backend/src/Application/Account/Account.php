<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use SmokeDetector\Application\Plan\CurrentPlan;

/**
 * What a user sees of the own account (GET /me): the profile, and the reduction plan once the
 * onboarding is done.
 */
final readonly class Account
{
    public function __construct(
        public User $user,
        /** Null until the onboarding (PUT /me/plan) has happened. */
        public ?CurrentPlan $plan,
    ) {
    }

    public function onboardingComplete(): bool
    {
        return $this->plan !== null;
    }
}
