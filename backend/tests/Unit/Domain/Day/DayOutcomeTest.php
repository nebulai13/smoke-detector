<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Day;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Day\DayOutcome;

/**
 * The facts about one finalized day that every scoring rule builds on.
 *
 * The tests carry the ID of the rule that uses the fact: "smoke-free" is the Appendix A day of
 * FR-GAME-01, "under the limit" is FR-GAME-03, and the limit itself comes from FR-PLAN-01. The
 * check-in window (SRS FR-LOG "yesterday can be checked in until it is finalized") is not part of
 * this class and has no test here.
 */
final class DayOutcomeTest extends TestCase
{
    public function test_FR_GAME_03_checked_in_day_at_or_below_the_limit_is_under_limit(): void
    {
        self::assertTrue(DayOutcome::checkedIn(count: 5, limit: 5)->isUnderLimit());
        self::assertTrue(DayOutcome::checkedIn(count: 0, limit: 5)->isUnderLimit());
        self::assertFalse(DayOutcome::checkedIn(count: 6, limit: 5)->isUnderLimit());
    }

    public function test_FR_GAME_03_day_without_check_in_is_never_under_limit(): void
    {
        self::assertFalse(DayOutcome::missed(count: 0, limit: 5)->isUnderLimit());
        self::assertFalse(DayOutcome::missed(count: 3, limit: 5)->isUnderLimit());
    }

    public function test_FR_GAME_01_day_with_zero_logs_is_smoke_free_only_after_check_in(): void
    {
        self::assertTrue(DayOutcome::checkedIn(count: 0, limit: 5)->isSmokeFree());
        self::assertFalse(DayOutcome::missed(count: 0, limit: 5)->isSmokeFree());
        self::assertFalse(DayOutcome::checkedIn(count: 1, limit: 5)->isSmokeFree());
    }

    public function test_FR_GAME_03_smoke_free_day_is_under_limit_even_when_the_limit_is_zero(): void
    {
        $day = DayOutcome::checkedIn(count: 0, limit: 0);

        self::assertTrue($day->isSmokeFree());
        self::assertTrue($day->isUnderLimit());
    }

    public function test_FR_GAME_03_negative_count_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DayOutcome::checkedIn(count: -1, limit: 5);
    }

    public function test_FR_PLAN_01_negative_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DayOutcome::missed(count: 0, limit: -1);
    }
}
