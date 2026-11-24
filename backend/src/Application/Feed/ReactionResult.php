<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

/**
 * What React answers (schema ReactionResult): the reaction as it is stored now, and what it earned.
 */
final readonly class ReactionResult
{
    public function __construct(
        public ReactionKey $reaction,
        /** 0 for a changed reaction, above the daily maximum, and before the onboarding. */
        public int $xp,
    ) {
    }
}
