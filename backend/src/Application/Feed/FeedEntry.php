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
        /** The reaction of the user who asks to this entry (HLD §4.13). */
        public ?ReactionKey $myReaction,
        /** @var list<ReactionView> Who reacted, oldest first: only on an own entry, only friends of now. */
        public array $reactions,
    ) {
    }

    /**
     * @param list<ReactionView> $reactions
     */
    public function withReactions(?ReactionKey $myReaction, array $reactions): self
    {
        return new self($this->id, $this->type, $this->user, $this->mine, $this->level, $this->badge, $this->milestone, $this->opponent, $myReaction, $reactions);
    }
}
