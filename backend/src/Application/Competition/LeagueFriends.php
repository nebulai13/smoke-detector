<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\FriendLinks;

/**
 * Who is in a user's leagues besides the user (HLD §4.9): the friends of today. A request that was
 * not accepted makes nobody a friend.
 */
final readonly class LeagueFriends
{
    /**
     * @return list<UserId>
     */
    public static function of(FriendLinks $links, UserId $userId): array
    {
        $friends = [];
        foreach ($links->ofUser($userId) as $link) {
            if ($link->isAccepted()) {
                $friends[] = $link->other($userId);
            }
        }

        return $friends;
    }
}
