<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Recovery;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Domain\Recovery\HealthMilestone;

/**
 * A health milestone a user has reached for good (HLD §4.3 "user_milestones", §4.11).
 */
final readonly class ReachedMilestone
{
    public function __construct(
        public UserId $userId,
        public HealthMilestone $milestone,
        /** When it was first reached: the end of its span after the last session up to a final day. */
        public DateTimeImmutable $reachedAt,
    ) {
    }
}
