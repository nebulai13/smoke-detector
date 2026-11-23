<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use SmokeDetector\Domain\Level\LevelProgress;
use SmokeDetector\Domain\Streak\StreakState;

/**
 * Streaks, XP and level as the user sees them (schema Progress): the stored progress with the
 * days that are not final yet projected onto it (HLD §4.6 "Provisional feedback").
 */
final readonly class ProgressView
{
    public function __construct(
        public StreakState $streaks,
        public int $totalXp,
        public LevelProgress $level,
        /** Whether the open days change what is stored: those numbers can still move. */
        public bool $provisional,
    ) {
    }
}
