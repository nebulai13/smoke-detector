<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;

/**
 * The reaction of one user to one feed entry (HLD §4.3 "feed_reactions", §4.13). A user has one per
 * entry: it can change its key, and keeps the time it was first sent.
 */
final readonly class Reaction
{
    public function __construct(
        /** The id of the feed entry. */
        public string $eventId,
        /** Who reacted. */
        public UserId $userId,
        public ReactionKey $key,
        /** The first time: a changed reaction keeps it. */
        public DateTimeImmutable $createdAt,
    ) {
    }
}
