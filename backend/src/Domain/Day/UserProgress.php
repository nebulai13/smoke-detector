<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Day;

use InvalidArgumentException;
use SmokeDetector\Domain\Streak\StreakState;

/**
 * What a user has built up so far: the streak counters and the XP total.
 *
 * It is the part of a user that finalizing a day reads and changes, and nothing more.
 */
final readonly class UserProgress
{
    public function __construct(
        public StreakState $streaks,
        /** Sum of all XP ever earned, from every source. The level is derived from it. */
        public int $totalXp,
    ) {
        if ($totalXp < 0) {
            throw new InvalidArgumentException('A total XP cannot be negative.');
        }
    }

    /**
     * The progress of a user who has no finalized day yet.
     */
    public static function initial(): self
    {
        return new self(StreakState::initial(), 0);
    }
}
