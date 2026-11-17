<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Day;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Streak\StreakState;

/**
 * What a user has built up before a day is finalized: streaks and total XP.
 */
final class UserProgressTest extends TestCase
{
    public function test_FR_GAME_05_new_user_starts_without_streaks_and_with_zero_xp(): void
    {
        $progress = UserProgress::initial();

        self::assertEquals(StreakState::initial(), $progress->streaks);
        self::assertSame(0, $progress->totalXp);
    }

    public function test_FR_GAME_01_progress_carries_the_streak_state_and_the_total_xp(): void
    {
        $streaks = new StreakState(3, 9, 2, 5, 1);

        $progress = new UserProgress($streaks, 420);

        self::assertSame($streaks, $progress->streaks);
        self::assertSame(420, $progress->totalXp);
    }

    public function test_FR_GAME_01_negative_total_xp_is_rejected(): void
    {
        // XP is only ever added, so a negative total can only be corrupt data.
        $this->expectException(InvalidArgumentException::class);

        new UserProgress(StreakState::initial(), -1);
    }
}
