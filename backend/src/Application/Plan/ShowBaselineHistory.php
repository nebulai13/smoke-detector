<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Application\Account\UserId;

/**
 * GET /me/baseline-history (FR-PLAN-08): every baseline the user ever had.
 */
final readonly class ShowBaselineHistory
{
    public function __construct(private BaselineHistory $baselines)
    {
    }

    /**
     * @return list<BaselineEntry> Oldest first. Empty before the onboarding.
     */
    public function execute(UserId $userId): array
    {
        return $this->baselines->ofUser($userId);
    }
}
