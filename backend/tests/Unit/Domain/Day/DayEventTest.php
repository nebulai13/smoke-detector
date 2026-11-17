<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Day;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\DayEventType;

/**
 * The events a finalized day sends to the clients, which map each one to an animation and a sound
 * (SRS FR-GAME-06, HLD §6 "Celebrations are server-driven").
 */
final class DayEventTest extends TestCase
{
    public function test_FR_GAME_06_event_names_are_stable_snake_case_strings(): void
    {
        // The names are part of the API contract. Renaming one breaks every released client.
        self::assertSame(
            [
                'checked_in',
                'under_limit_day',
                'smoke_free_day',
                'streak_extended',
                'streak_milestone',
                'freeze_earned',
                'freeze_used',
                'streak_broken',
                'level_up',
            ],
            array_map(static fn (DayEventType $type): string => $type->value, DayEventType::cases()),
        );
    }

    public function test_FR_LOG_06_no_event_exists_for_going_over_the_limit_or_losing_something(): void
    {
        $names = array_map(static fn (DayEventType $type): string => $type->value, DayEventType::cases());

        // The streak module reports the first two for the history. Clients must never get a sound for them.
        self::assertNotContains('under_limit_streak_broken', $names);
        self::assertNotContains('freeze_lost', $names);
        self::assertNotContains('over_limit_day', $names);
    }

    public function test_FR_GAME_06_event_carries_its_type_and_an_optional_number(): void
    {
        $withValue = new DayEvent(DayEventType::StreakExtended, 12);
        $withoutValue = new DayEvent(DayEventType::CheckedIn);

        self::assertSame(DayEventType::StreakExtended, $withValue->type);
        self::assertSame(12, $withValue->value);
        self::assertNull($withoutValue->value);
    }

    public function test_FR_GAME_06_zero_is_a_valid_event_value(): void
    {
        // freeze_used with no freeze left.
        self::assertSame(0, new DayEvent(DayEventType::FreezeUsed, 0)->value);
    }

    public function test_FR_GAME_06_negative_event_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DayEvent(DayEventType::LevelUp, -1);
    }
}
