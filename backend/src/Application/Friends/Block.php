<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;

/**
 * One user has blocked another (HLD §4.3 "blocks"). A block has a direction: each of two users can
 * block the other, and only the blocker can lift it.
 */
final readonly class Block
{
    public function __construct(
        public UserId $blocker,
        public UserId $blocked,
        public DateTimeImmutable $blockedAt,
    ) {
    }
}
