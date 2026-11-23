<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use DateTimeImmutable;

/**
 * An open friend request as the two see it: the other one's public profile and when it was sent.
 */
final readonly class PendingRequest
{
    public function __construct(
        /** Who asked (a request to the user) or who was asked (a request of the user). */
        public PublicProfile $user,
        public DateTimeImmutable $requestedAt,
    ) {
    }
}
