<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Streak;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Streak\StreakRules;

/**
 * Tunable numbers of streaks and streak freezes, SRS FR-GAME-04 and Appendix A.
 */
final class StreakRulesTest extends TestCase
{
    public function test_FR_GAME_04_defaults_are_a_freeze_every_7_days_and_at_most_2_held(): void
    {
        $rules = new StreakRules();

        self::assertSame(7, $rules->freezeInterval);
        self::assertSame(2, $rules->maxFreezesHeld);
    }

    public function test_FR_GAME_01_default_milestones_are_7_30_100_and_365_days(): void
    {
        self::assertSame([7, 30, 100, 365], new StreakRules()->milestoneThresholds);
    }

    public function test_FR_GAME_04_shortest_freeze_interval_is_a_single_day(): void
    {
        self::assertSame(1, new StreakRules(freezeInterval: 1)->freezeInterval);
    }

    public function test_FR_GAME_04_freezes_can_be_switched_off_with_a_cap_of_zero(): void
    {
        self::assertSame(0, new StreakRules(maxFreezesHeld: 0)->maxFreezesHeld);
    }

    /**
     * @param list<int> $milestoneThresholds
     */
    #[DataProvider('invalidRules')]
    public function test_FR_GAME_04_invalid_rules_are_rejected(
        int $freezeInterval,
        int $maxFreezesHeld,
        array $milestoneThresholds,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new StreakRules($freezeInterval, $maxFreezesHeld, $milestoneThresholds);
    }

    /**
     * @return iterable<string, array{int, int, list<int>}>
     */
    public static function invalidRules(): iterable
    {
        yield 'freeze interval of zero' => [0, 2, [7, 30]];
        yield 'negative freeze interval' => [-7, 2, [7, 30]];
        yield 'negative freeze cap' => [7, -1, [7, 30]];
        yield 'milestone at zero' => [7, 2, [0, 7]];
        yield 'negative milestone' => [7, 2, [-7, 30]];
        yield 'milestones not ascending' => [7, 2, [30, 7]];
        yield 'duplicate milestone' => [7, 2, [7, 7, 30]];
    }
}
