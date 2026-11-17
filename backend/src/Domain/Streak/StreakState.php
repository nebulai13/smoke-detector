<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Streak;

use InvalidArgumentException;

/**
 * A user's streak counters after their latest finalized day (SRS FR-GAME-02 to FR-GAME-04).
 *
 * The fields are plain public integers so the state maps one-to-one onto a database row.
 */
final readonly class StreakState
{
    public function __construct(
        /** Consecutive finalized days with a check-in. */
        public int $checkInCurrent,
        public int $checkInLongest,
        /** Consecutive finalized days that were checked in with a count at or below the limit. */
        public int $underLimitCurrent,
        public int $underLimitLongest,
        public int $freezesHeld,
    ) {
        if (min($checkInCurrent, $checkInLongest, $underLimitCurrent, $underLimitLongest, $freezesHeld) < 0) {
            throw new InvalidArgumentException('Streak lengths and freezes cannot be negative.');
        }
        if ($checkInLongest < $checkInCurrent || $underLimitLongest < $underLimitCurrent) {
            throw new InvalidArgumentException('A longest streak cannot be shorter than the current streak.');
        }
        // Every under-limit day is also a checked-in day, so a longer under-limit streak means corrupt data.
        if ($underLimitCurrent > $checkInCurrent || $underLimitLongest > $checkInLongest) {
            throw new InvalidArgumentException('An under-limit streak cannot be longer than the check-in streak.');
        }
    }

    /**
     * The state of a user who has no finalized day yet.
     */
    public static function initial(): self
    {
        return new self(0, 0, 0, 0, 0);
    }
}
