<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Recovery;

use DateTimeImmutable;

/**
 * One health milestone as seen at a given moment.
 */
final readonly class HealthMilestoneState
{
    public function __construct(
        public HealthMilestone $milestone,
        /** The moment the milestone is reached, or will be. */
        public DateTimeImmutable $reachedAt,
        public bool $reached,
    ) {
    }
}
