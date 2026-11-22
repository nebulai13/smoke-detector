<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Domain\Plan\Goal;

/**
 * The name of the two kinds of goal, as the API and the `plans` table spell them (FR-ACC-06).
 * The rules themselves only know the Goal and its target.
 */
enum GoalMode: string
{
    /** Come down to a fixed number of sessions per day, at least 1. */
    case Reduce = 'reduce';
    /** Come down to 0. */
    case Quit = 'quit';

    public static function of(Goal $goal): self
    {
        return $goal->isQuit() ? self::Quit : self::Reduce;
    }
}
