<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Level;

use InvalidArgumentException;

/**
 * Tunable number of the level table (SRS FR-GAME-05, Appendix A).
 */
final readonly class LevelRules
{
    /**
     * Upper bound of the factor. It keeps every number the level maths produces inside a 64-bit
     * integer for any XP total, and is far above any value that makes sense for the product.
     */
    public const int MAX_FACTOR = 1_000_000_000;

    public function __construct(
        /** Level n requires a cumulative factor × n × (n + 1) XP. */
        public int $factor = 50,
    ) {
        if ($factor < 1 || $factor > self::MAX_FACTOR) {
            throw new InvalidArgumentException('The level factor must be between 1 and ' . self::MAX_FACTOR . '.');
        }
    }
}
