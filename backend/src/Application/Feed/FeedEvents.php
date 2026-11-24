<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use SmokeDetector\Application\Account\UserId;

/**
 * The entries of the activity feed of all users (HLD §4.3 "feed_events"): written once, never changed.
 */
interface FeedEvents
{
    public function append(FeedEvent $event): void;

    public function find(string $id): ?FeedEvent;

    /**
     * The newest entries about these users, the one written last first, whatever the times say:
     * several steps of one cron run can have the same time, and a clock can be put back.
     *
     * @param list<UserId> $userIds
     * @param int          $skip    How many of the newest are left out, in that order: to read on
     *                              where an earlier read ended.
     *
     * @return list<FeedEvent> At most $limit.
     *
     * @throws \InvalidArgumentException For a negative $limit or $skip: a mistake of the caller,
     *                                   never handed to the database.
     */
    public function newestOf(array $userIds, int $limit, int $skip = 0): array;

    /**
     * @return list<FeedEvent> The entries about the user, oldest first. Being the opponent in
     *                         somebody else's entry does not make it one of them.
     */
    public function ofUser(UserId $userId): array;
}
