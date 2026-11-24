<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;

/**
 * One entry of the activity feed as it is stored (HLD §4.3 "feed_events", §4.12): something a user
 * reached, written in the transaction that made it final.
 */
final readonly class FeedEvent
{
    public function __construct(
        /** Random (FeedIds): it says nothing about when the entry was written. */
        public string $id,
        /** Whom the entry is about. */
        public UserId $userId,
        public FeedEventType $type,
        /** The level, the badge or the health milestone; null for a duel. Kept as text, so a value a later version wrote can be read and left out. */
        public ?string $detail,
        /** The opponent of a duel; null otherwise, and once that account is gone. */
        public ?UserId $otherUserId,
        /** For the data export only: an entry is shown without a time. */
        public DateTimeImmutable $createdAt,
    ) {
    }
}
