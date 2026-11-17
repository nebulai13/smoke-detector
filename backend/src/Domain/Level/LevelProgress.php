<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Level;

use InvalidArgumentException;

/**
 * Where a user stands inside the current level, for the progress bar: xpIntoLevel / xpForNextLevel.
 */
final readonly class LevelProgress
{
    public function __construct(
        public int $level,
        /** XP earned since the current level was reached. */
        public int $xpIntoLevel,
        /** Full distance from the current level's threshold to the next one (not what is still missing). */
        public int $xpForNextLevel,
    ) {
        if ($level < 0) {
            throw new InvalidArgumentException('A level cannot be negative.');
        }
        if ($xpIntoLevel < 0 || $xpIntoLevel >= $xpForNextLevel) {
            throw new InvalidArgumentException(
                'The XP into a level must be at least 0 and below the XP for the next level.',
            );
        }
    }

    /**
     * XP still missing until the next level.
     */
    public function xpRemaining(): int
    {
        return $this->xpForNextLevel - $this->xpIntoLevel;
    }
}
