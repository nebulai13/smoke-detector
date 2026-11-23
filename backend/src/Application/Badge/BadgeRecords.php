<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Badge;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Domain\Badge\Badge;

/**
 * The badges of all users (HLD §4.3 "user_badges"): a user holds a badge once.
 */
interface BadgeRecords
{
    /**
     * Stores the badge unless the user holds it already: the first award stays.
     *
     * @return bool Whether it was stored now.
     */
    public function add(AwardedBadge $badge): bool;

    /**
     * @return list<AwardedBadge> The badges of the user, in the order of the Badge cases.
     */
    public function ofUser(UserId $userId): array;

    /**
     * Which badges these users hold, without the time: what a friend may see (SRS FR-FRND-03).
     *
     * @param list<UserId> $userIds
     *
     * @return array<string, list<Badge>> By user id, each list in the order of the Badge cases. A user
     *                                    without a badge is not in it.
     */
    public function heldBy(array $userIds): array;
}
