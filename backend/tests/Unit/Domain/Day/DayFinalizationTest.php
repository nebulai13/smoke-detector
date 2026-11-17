<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Day;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\DayEventType;
use SmokeDetector\Domain\Day\DayFinalization;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Streak\StreakState;
use SmokeDetector\Domain\Xp\XpAward;
use SmokeDetector\Domain\Xp\XpSource;

/**
 * The result of finalizing one user-day.
 */
final class DayFinalizationTest extends TestCase
{
    public function test_FR_GAME_01_xp_earned_is_the_sum_of_the_awards_of_the_day(): void
    {
        $result = self::finalization([
            new XpAward(XpSource::CheckIn, 10),
            new XpAward(XpSource::UnderLimit, 20),
            new XpAward(XpSource::StreakMilestone, 50, 7),
        ], []);

        self::assertSame(80, $result->xpEarned());
    }

    public function test_FR_GAME_01_day_without_awards_earned_zero_xp(): void
    {
        self::assertSame(0, self::finalization([], [])->xpEarned());
    }

    public function test_FR_GAME_02_progress_after_the_day_is_the_input_for_the_next_day(): void
    {
        $result = self::finalization([], []);

        self::assertEquals(new UserProgress($result->streaks, 340), $result->progress());
        self::assertSame($result->streaks, $result->progress()->streaks);
    }

    public function test_FR_GAME_06_events_of_one_type_can_be_picked_out_in_order(): void
    {
        $result = self::finalization([], [
            new DayEvent(DayEventType::CheckedIn),
            new DayEvent(DayEventType::LevelUp, 1),
            new DayEvent(DayEventType::LevelUp, 2),
        ]);

        self::assertEquals(
            [new DayEvent(DayEventType::LevelUp, 1), new DayEvent(DayEventType::LevelUp, 2)],
            $result->eventsOfType(DayEventType::LevelUp),
        );
        self::assertSame([], $result->eventsOfType(DayEventType::StreakBroken));
    }

    /**
     * @param list<XpAward>  $awards
     * @param list<DayEvent> $events
     */
    private static function finalization(array $awards, array $events): DayFinalization
    {
        return new DayFinalization(new StreakState(7, 7, 7, 7, 1), $awards, 340, 1, 2, $events);
    }
}
