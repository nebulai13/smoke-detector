<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Recovery\HealthMilestone;

/**
 * One entry of the feed as a user sees it (HLD §4.12 "What a user sees"). It has no time on purpose
 * (SRS FR-FRND-03): the order of the list is all there is.
 */
final readonly class FeedEntry
{
    public function __construct(
        public string $id,
        public FeedEventType $type,
        /** Whom the entry is about. */
        public PublicProfile $user,
        /** Whether it is about the user who asks. */
        public bool $mine,
        /** The level reached, for a level-up. */
        public ?int $level,
        public ?Badge $badge,
        public ?HealthMilestone $milestone,
        /** Of a duel: the other user, if the one who asks is that user or a friend of theirs. */
        public ?PublicProfile $opponent,
    ) {
    }
}
