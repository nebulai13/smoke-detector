<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use SmokeDetector\Application\RateLimit\RateLimit;

/**
 * The rate limit of what a user does to friends (HLD §4.13, §4.14, SRS FR-SOC-04): reactions and
 * nudges count towards one limit. Kept here once, so that the two use cases cannot drift apart.
 */
final readonly class SocialLimits
{
    /** Reactions and nudges together, per user and day in UTC. */
    public const int DAILY_ACTIONS = 30;

    public static function actionsPerUser(): RateLimit
    {
        return new RateLimit('social.actions', self::DAILY_ACTIONS, 86400);
    }
}
