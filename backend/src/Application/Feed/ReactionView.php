<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use SmokeDetector\Application\Friends\PublicProfile;

/**
 * Who reacted to an entry, and how, as the owner of the entry sees it (HLD §4.13 "Who sees a reaction").
 */
final readonly class ReactionView
{
    public function __construct(
        public PublicProfile $user,
        public ReactionKey $reaction,
    ) {
    }
}
