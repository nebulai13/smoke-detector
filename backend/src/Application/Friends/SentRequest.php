<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

/**
 * The answer to a friend request: whom the user asked, and whether the two are friends now
 * (because the owner of the code had asked the user before).
 */
final readonly class SentRequest
{
    public function __construct(
        public bool $accepted,
        /** The owner of the code. */
        public PublicProfile $user,
    ) {
    }
}
