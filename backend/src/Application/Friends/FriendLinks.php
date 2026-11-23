<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use SmokeDetector\Application\Account\UserId;

/**
 * Friend requests and friendships (HLD §4.3 "friendships"). Two users have one link at most,
 * whichever of them asked: it is found, replaced and deleted from either side.
 */
interface FriendLinks
{
    /**
     * The link of the two, in either order, or null when there is none.
     */
    public function between(UserId $a, UserId $b): ?Friendship;

    /**
     * Stores the link, replacing the one the two users had, whoever had asked for that one.
     */
    public function save(Friendship $friendship): void;

    /**
     * Deletes the link of the two, in either order. Deleting a link that does not exist does nothing.
     */
    public function delete(UserId $a, UserId $b): void;

    /**
     * @return list<Friendship> Every link the user is part of, requests and friendships, the oldest
     *                          request first; in the same second, the smaller id (as text) of the
     *                          other user first.
     */
    public function ofUser(UserId $userId): array;
}
