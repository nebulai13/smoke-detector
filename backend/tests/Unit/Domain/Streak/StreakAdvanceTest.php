<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Streak;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Streak\StreakAdvance;
use SmokeDetector\Domain\Streak\StreakEvent;
use SmokeDetector\Domain\Streak\StreakEventType;
use SmokeDetector\Domain\Streak\StreakState;

/**
 * The result of advancing the streaks by one finalized day: the new state plus what happened.
 */
final class StreakAdvanceTest extends TestCase
{
    public function test_FR_GAME_02_advance_exposes_the_new_state_and_the_events_in_order(): void
    {
        $state = new StreakState(7, 7, 0, 0, 1);
        $events = [
            new StreakEvent(StreakEventType::CheckInStreakExtended, 7),
            new StreakEvent(StreakEventType::FreezeEarned, 1),
        ];

        $advance = new StreakAdvance($state, $events);

        self::assertSame($state, $advance->state);
        self::assertSame($events, $advance->events);
    }

    public function test_FR_GAME_02_single_event_can_be_looked_up_by_type(): void
    {
        $earned = new StreakEvent(StreakEventType::FreezeEarned, 1);
        $advance = new StreakAdvance(
            new StreakState(7, 7, 0, 0, 1),
            [new StreakEvent(StreakEventType::CheckInStreakExtended, 7), $earned],
        );

        self::assertSame($earned, $advance->event(StreakEventType::FreezeEarned));
        self::assertNull($advance->event(StreakEventType::FreezeUsed));
    }
}
