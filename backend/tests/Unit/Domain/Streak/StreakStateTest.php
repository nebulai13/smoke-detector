<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Streak;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Streak\StreakState;

/**
 * A user's streak counters between two finalized days, SRS FR-GAME-02 to FR-GAME-04.
 */
final class StreakStateTest extends TestCase
{
    public function test_FR_GAME_02_new_user_starts_without_streaks_or_freezes(): void
    {
        $state = StreakState::initial();

        self::assertSame(0, $state->checkInCurrent);
        self::assertSame(0, $state->checkInLongest);
        self::assertSame(0, $state->underLimitCurrent);
        self::assertSame(0, $state->underLimitLongest);
        self::assertSame(0, $state->freezesHeld);
    }

    public function test_FR_GAME_02_stored_state_is_restored_field_by_field(): void
    {
        $state = new StreakState(
            checkInCurrent: 9,
            checkInLongest: 31,
            underLimitCurrent: 4,
            underLimitLongest: 12,
            freezesHeld: 1,
        );

        self::assertSame(9, $state->checkInCurrent);
        self::assertSame(31, $state->checkInLongest);
        self::assertSame(4, $state->underLimitCurrent);
        self::assertSame(12, $state->underLimitLongest);
        self::assertSame(1, $state->freezesHeld);
    }

    public function test_FR_GAME_02_current_streak_may_equal_the_longest_streak(): void
    {
        $state = new StreakState(7, 7, 7, 7, 1);

        self::assertSame($state->checkInLongest, $state->checkInCurrent);
        self::assertSame($state->underLimitLongest, $state->underLimitCurrent);
    }

    #[DataProvider('invalidStates')]
    public function test_FR_GAME_02_impossible_state_is_rejected(
        int $checkInCurrent,
        int $checkInLongest,
        int $underLimitCurrent,
        int $underLimitLongest,
        int $freezesHeld,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new StreakState($checkInCurrent, $checkInLongest, $underLimitCurrent, $underLimitLongest, $freezesHeld);
    }

    /**
     * @return iterable<string, array{int, int, int, int, int}>
     */
    public static function invalidStates(): iterable
    {
        yield 'negative check-in streak' => [-1, 5, 0, 0, 0];
        yield 'negative longest check-in streak' => [0, -1, 0, 0, 0];
        yield 'negative under-limit streak' => [5, 5, -1, 3, 0];
        yield 'negative longest under-limit streak' => [5, 5, 0, -1, 0];
        yield 'negative freezes' => [5, 5, 0, 0, -1];
        yield 'longest check-in streak below the current one' => [6, 5, 0, 0, 0];
        yield 'longest under-limit streak below the current one' => [6, 6, 4, 3, 0];
        // Every under-limit day is also a checked-in day, so it can never run ahead.
        yield 'under-limit streak longer than the check-in streak' => [3, 9, 4, 4, 0];
        yield 'longest under-limit streak longer than the longest check-in streak' => [0, 5, 0, 6, 0];
    }
}
