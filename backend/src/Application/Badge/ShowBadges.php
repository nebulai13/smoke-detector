<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Badge;

use SmokeDetector\Application\Account\UserId;

/**
 * GET /me/badges (SRS FR-GAME-08, HLD §4.10): the badges the user holds, with the time of each.
 * For the user alone: a friend sees which badges, never when (FriendList).
 */
final readonly class ShowBadges
{
    public function __construct(private BadgeRecords $badges)
    {
    }

    /**
     * @return list<AwardedBadge> In the order of the Badge cases. Empty before the first one.
     */
    public function of(UserId $userId): array
    {
        return $this->badges->ofUser($userId);
    }
}
