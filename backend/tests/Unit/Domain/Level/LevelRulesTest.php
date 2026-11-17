<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Level;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Level\LevelRules;

/**
 * The tunable number of the level table, SRS FR-GAME-05 and Appendix A.
 */
final class LevelRulesTest extends TestCase
{
    public function test_FR_GAME_05_default_factor_is_the_50_of_appendix_a(): void
    {
        self::assertSame(50, (new LevelRules())->factor);
    }

    public function test_FR_GAME_05_factor_of_zero_is_rejected(): void
    {
        // With a factor of 0 every level would cost 0 XP, so there would be no highest level to be on.
        $this->expectException(InvalidArgumentException::class);

        new LevelRules(factor: 0);
    }

    public function test_FR_GAME_05_negative_factor_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LevelRules(factor: -50);
    }

    public function test_FR_GAME_05_rejected_factor_names_the_allowed_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The level factor must be between 1 and 1000000000.');

        new LevelRules(factor: 0);
    }

    public function test_FR_GAME_05_largest_allowed_factor_is_accepted(): void
    {
        self::assertSame(LevelRules::MAX_FACTOR, (new LevelRules(factor: LevelRules::MAX_FACTOR))->factor);
    }

    public function test_FR_GAME_05_factor_above_the_documented_maximum_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LevelRules(factor: LevelRules::MAX_FACTOR + 1);
    }
}
