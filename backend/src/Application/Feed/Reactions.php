<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use SmokeDetector\Application\Account\UserId;

/**
 * The reactions to the feed entries of all users (HLD §4.3 "feed_reactions", §4.13).
 */
interface Reactions
{
    public function find(string $eventId, UserId $userId): ?Reaction;

    /**
     * Stores the reaction. When the user has one to that entry already, that one changes its key and
     * keeps its time.
     *
     * @return bool True when the user had no reaction to that entry before: a reaction more.
     */
    public function save(Reaction $reaction): bool;

    /**
     * @param list<string> $eventIds
     *
     * @return array<string, list<Reaction>> By entry id, oldest first. An entry without a reaction is
     *                                       not in it.
     */
    public function ofEvents(array $eventIds): array;

    /**
     * How many reactions the user has sent: to any entry, also to those that left the feed.
     */
    public function countSentBy(UserId $userId): int;

    /**
     * @return list<Reaction> The reactions the user sent, oldest first.
     */
    public function sentBy(UserId $userId): array;
}
