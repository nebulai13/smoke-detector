<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Settings;

use SmokeDetector\Domain\Recovery\MilestoneSharing;

/**
 * The settings of a user (HLD §4.11 "The setting", SRS FR-GAME-09). The notification settings of
 * step 7 will join them.
 */
final readonly class Settings
{
    public function __construct(
        /** What friends see of the user's health milestones. */
        public MilestoneSharing $milestoneSharing,
    ) {
    }

    /**
     * The settings of somebody who never chose.
     */
    public static function defaults(): self
    {
        return new self(MilestoneSharing::default());
    }
}
