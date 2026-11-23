<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use SmokeDetector\Domain\Day\DayEvent;

/**
 * The answer to a check-in: the day, what it gives to celebrate as things stand, and the progress
 * with the open days projected. Both are provisional until the day is final (FR-GAME-06).
 */
final readonly class CheckInResult
{
    /**
     * @param list<DayEvent> $events In the order to play them.
     */
    public function __construct(
        public DayView $day,
        public array $events,
        public ProgressView $progress,
    ) {
    }
}
