<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use DateTimeImmutable;

/**
 * A user the user has blocked, as GET /friends/blocked lists them.
 */
final readonly class BlockedUser
{
    public function __construct(
        public PublicProfile $user,
        public DateTimeImmutable $blockedAt,
    ) {
    }
}
