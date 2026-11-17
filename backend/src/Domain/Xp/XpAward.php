<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Xp;

use InvalidArgumentException;

/**
 * One XP event: what it was given for and how much.
 */
final readonly class XpAward
{
    public function __construct(
        public XpSource $source,
        public int $amount,
        /** The streak length for a streak milestone, the rank for a league. Null for every other source. */
        public ?int $detail = null,
    ) {
        // XP only ever rewards. An award of zero would show up as an empty celebration.
        if ($amount < 1) {
            throw new InvalidArgumentException('An XP award must be at least 1 XP.');
        }
    }
}
