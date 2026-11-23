<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Recovery;

use DateTimeImmutable;
use SmokeDetector\Domain\Recovery\HealthMilestoneState;

/**
 * GET /me/health-milestones: where the user stands since the last logged session.
 */
final readonly class HealthView
{
    /**
     * @param list<HealthMilestoneState> $milestones Every milestone in its order; empty when $since is null.
     */
    public function __construct(
        /** The last logged session that is not deleted. Null without one: nothing to count from. */
        public ?DateTimeImmutable $since,
        /** Every logged session was vaping, or without a log the plan names vaping: the evidence differs. */
        public bool $vapingOnly,
        public array $milestones,
    ) {
    }
}
