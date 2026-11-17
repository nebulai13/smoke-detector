<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Streak;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Streak\StreakEvent;
use SmokeDetector\Domain\Streak\StreakEventType;

/**
 * What happened to the streaks on one finalized day, as input for XP, badges and celebrations.
 */
final class StreakEventTest extends TestCase
{
    public function test_FR_GAME_02_event_types_keep_their_stored_snake_case_names(): void
    {
        // These strings are stored and sent to the clients, so renaming one is a breaking change.
        self::assertSame(
            [
                'check_in_streak_extended',
                'check_in_streak_milestone_reached',
                'check_in_streak_broken',
                'under_limit_streak_extended',
                'under_limit_streak_broken',
                'freeze_earned',
                'freeze_lost',
                'freeze_used',
            ],
            array_map(static fn (StreakEventType $type): string => $type->value, StreakEventType::cases()),
        );
    }

    public function test_FR_GAME_02_event_carries_its_type_and_value(): void
    {
        $event = new StreakEvent(StreakEventType::CheckInStreakBroken, 12);

        self::assertSame(StreakEventType::CheckInStreakBroken, $event->type);
        self::assertSame(12, $event->value);
    }

    public function test_FR_GAME_02_event_value_is_optional(): void
    {
        self::assertNull(new StreakEvent(StreakEventType::FreezeUsed)->value);
    }

    public function test_FR_GAME_02_negative_event_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StreakEvent(StreakEventType::CheckInStreakExtended, -1);
    }
}
