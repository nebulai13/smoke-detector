<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Badge\AwardedBadge;
use SmokeDetector\Application\Badge\BadgeRecords;

/**
 * Badge records that count how often they are read and written, and pass everything on.
 */
final class CountingBadgeRecords implements BadgeRecords
{
    public int $reads = 0;
    public int $writes = 0;

    public function __construct(private readonly BadgeRecords $inner)
    {
    }

    public function add(AwardedBadge $badge): bool
    {
        $this->writes++;

        return $this->inner->add($badge);
    }

    public function ofUser(UserId $userId): array
    {
        $this->reads++;

        return $this->inner->ofUser($userId);
    }

    public function heldBy(array $userIds): array
    {
        $this->reads++;

        return $this->inner->heldBy($userIds);
    }
}
