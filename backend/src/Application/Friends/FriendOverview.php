<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

/**
 * GET /friends: the friends of a user and the open requests, each list on its own.
 */
final readonly class FriendOverview
{
    /**
     * @param list<FriendView>     $friends  By name without regard to capitals, then by user id.
     * @param list<PendingRequest> $incoming Requests the user can accept or decline, oldest first.
     * @param list<PendingRequest> $outgoing Requests the user has sent and can take back, oldest first.
     */
    public function __construct(
        public array $friends,
        public array $incoming,
        public array $outgoing,
    ) {
    }
}
