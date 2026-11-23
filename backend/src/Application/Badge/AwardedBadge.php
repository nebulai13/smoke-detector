<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Badge;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Domain\Badge\Badge;

/**
 * A badge a user holds (HLD §4.3 "user_badges", SRS FR-GAME-08).
 */
final readonly class AwardedBadge
{
    public function __construct(
        public UserId $userId,
        public Badge $badge,
        /** When it was first awarded: when the day, the duel or the rank that justified it became final. */
        public DateTimeImmutable $awardedAt,
    ) {
    }
}
