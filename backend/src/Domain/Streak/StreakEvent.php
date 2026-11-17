<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Streak;

use InvalidArgumentException;

/**
 * One thing that happened to a user's streaks on a finalized day.
 *
 * XP, badges and celebrations are driven from these events, so the streak rules live in one place.
 */
final readonly class StreakEvent
{
    public function __construct(
        public StreakEventType $type,
        /** A length, threshold or freeze count. Its meaning is documented on each StreakEventType case. */
        public ?int $value = null,
    ) {
        if ($value !== null && $value < 0) {
            throw new InvalidArgumentException('A streak event value cannot be negative.');
        }
    }
}
