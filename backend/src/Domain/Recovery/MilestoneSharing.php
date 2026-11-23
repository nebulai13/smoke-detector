<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Recovery;

/**
 * What a user lets friends see of the health milestones (SRS FR-GAME-09, FR-FRND-03, HLD §4.11):
 * the user's own setting, decided by the owner on 2026-11-23. The values are stored and sent to the
 * clients, so they must stay stable.
 */
enum MilestoneSharing: string
{
    /** The default: the milestones from two weeks on. */
    case FromTwoWeeks = 'from_two_weeks';
    case Private = 'private';
    /** Also the two short ones, which say that a night passed without a session. */
    case All = 'all';

    public static function default(): self
    {
        return self::FromTwoWeeks;
    }

    public function shows(HealthMilestone $milestone): bool
    {
        return match ($this) {
            // Two weeks is the span of the circulation milestone: the name stays true if the milestones change.
            self::FromTwoWeeks => $milestone->afterMinutes() >= HealthMilestone::Circulation->afterMinutes(),
            self::Private => false,
            self::All => true,
        };
    }
}
