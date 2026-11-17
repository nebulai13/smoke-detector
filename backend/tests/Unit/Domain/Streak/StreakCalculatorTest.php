<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Streak;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Day\DayOutcome;
use SmokeDetector\Domain\Streak\StreakAdvance;
use SmokeDetector\Domain\Streak\StreakCalculator;
use SmokeDetector\Domain\Streak\StreakEvent;
use SmokeDetector\Domain\Streak\StreakEventType;
use SmokeDetector\Domain\Streak\StreakRules;
use SmokeDetector\Domain\Streak\StreakState;

/**
 * Streaks and streak freezes, SRS FR-GAME-02 to FR-GAME-04, one finalized day at a time.
 *
 * Day sequences are written as strings, oldest day first:
 * U = checked in at or under the limit, O = checked in over the limit, M = missed (no check-in).
 *
 * A "frozen day" is a missed day on which a streak freeze was used. The freeze protects the
 * check-in streak only: the under-limit streak ends on every missed day (FR-GAME-03).
 */
final class StreakCalculatorTest extends TestCase
{
    private StreakCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new StreakCalculator(new StreakRules());
    }

    // ---- FR-GAME-02: check-in streak ----

    public function test_FR_GAME_02_checked_in_day_extends_the_check_in_streak(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 3), self::over());

        self::assertSame(4, $advance->state->checkInCurrent);
        self::assertEquals(
            new StreakEvent(StreakEventType::CheckInStreakExtended, 4),
            $advance->event(StreakEventType::CheckInStreakExtended),
        );
    }

    public function test_FR_GAME_02_first_check_in_of_a_new_user_starts_both_streaks(): void
    {
        $advance = $this->calculator->advance(StreakState::initial(), self::under());

        self::assertEquals(new StreakState(1, 1, 1, 1, 0), $advance->state);
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::CheckInStreakExtended, 1),
                new StreakEvent(StreakEventType::UnderLimitStreakExtended, 1),
            ],
            $advance->events,
        );
    }

    public function test_FR_GAME_02_going_over_the_limit_does_not_break_the_check_in_streak(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 12, underLimit: 5), self::over());

        self::assertSame(13, $advance->state->checkInCurrent);
        self::assertNull($advance->event(StreakEventType::CheckInStreakBroken));
    }

    public function test_FR_GAME_02_missed_day_without_a_freeze_resets_the_check_in_streak(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 12, freezes: 0), self::missed());

        self::assertSame(0, $advance->state->checkInCurrent);
        self::assertEquals(
            new StreakEvent(StreakEventType::CheckInStreakBroken, 12),
            $advance->event(StreakEventType::CheckInStreakBroken),
        );
    }

    public function test_FR_GAME_02_missed_day_without_any_streak_reports_nothing_broken(): void
    {
        $state = self::state(checkIn: 0, checkInLongest: 9, underLimitLongest: 4);

        $advance = $this->calculator->advance($state, self::missed());

        self::assertEquals($state, $advance->state);
        self::assertSame([], $advance->events);
    }

    public function test_FR_GAME_02_missed_day_without_an_under_limit_streak_reports_only_the_check_in_streak_broken(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 8, underLimit: 0, freezes: 0), self::missed());

        self::assertEquals([new StreakEvent(StreakEventType::CheckInStreakBroken, 8)], $advance->events);
    }

    public function test_FR_GAME_02_check_in_streak_can_grow_up_to_the_largest_integer(): void
    {
        $almost = PHP_INT_MAX - 1;

        $advance = $this->calculator->advance(new StreakState($almost, $almost, $almost, $almost, 0), self::under());

        self::assertSame(PHP_INT_MAX, $advance->state->checkInCurrent);
        self::assertSame(PHP_INT_MAX, $advance->state->checkInLongest);
        self::assertSame(PHP_INT_MAX, $advance->state->underLimitCurrent);
        self::assertSame(PHP_INT_MAX, $advance->state->underLimitLongest);
    }

    public function test_FR_GAME_02_check_in_streak_at_the_integer_limit_cannot_be_extended(): void
    {
        // One more day would turn the counter into a float.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The check-in streak is too long to be extended.');

        $this->calculator->advance(new StreakState(PHP_INT_MAX, PHP_INT_MAX, 0, 0, 0), DayOutcome::checkedIn(0, 0));
    }

    public function test_FR_GAME_02_check_in_streak_at_the_integer_limit_can_still_be_missed(): void
    {
        $advance = $this->calculator->advance(new StreakState(PHP_INT_MAX, PHP_INT_MAX, 0, 0, 0), self::missed());

        self::assertEquals(new StreakState(0, PHP_INT_MAX, 0, 0, 0), $advance->state);
    }

    public function test_FR_GAME_02_longest_check_in_streak_grows_with_a_new_personal_best(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 9, checkInLongest: 9), self::over());

        self::assertSame(10, $advance->state->checkInLongest);
    }

    public function test_FR_GAME_02_longest_check_in_streak_never_decreases(): void
    {
        $state = self::state(checkIn: 3, checkInLongest: 20);

        self::assertSame(20, $this->calculator->advance($state, self::under())->state->checkInLongest);
        self::assertSame(20, $this->calculator->advance($state, self::over())->state->checkInLongest);
        self::assertSame(20, $this->calculator->advance($state, self::missed())->state->checkInLongest);
    }

    // ---- FR-GAME-03: under-limit streak ----

    public function test_FR_GAME_03_checked_in_day_at_or_under_the_limit_extends_the_under_limit_streak(): void
    {
        $state = self::state(checkIn: 8, underLimit: 2);

        $atLimit = $this->calculator->advance($state, DayOutcome::checkedIn(count: 5, limit: 5));
        $smokeFree = $this->calculator->advance($state, DayOutcome::checkedIn(count: 0, limit: 0));

        self::assertSame(3, $atLimit->state->underLimitCurrent);
        self::assertSame(3, $smokeFree->state->underLimitCurrent);
        self::assertEquals(
            new StreakEvent(StreakEventType::UnderLimitStreakExtended, 3),
            $atLimit->event(StreakEventType::UnderLimitStreakExtended),
        );
    }

    public function test_FR_GAME_03_checked_in_day_over_the_limit_resets_the_under_limit_streak(): void
    {
        $advance = $this->calculator->advance(
            self::state(checkIn: 8, underLimit: 5),
            DayOutcome::checkedIn(count: 6, limit: 5),
        );

        self::assertSame(0, $advance->state->underLimitCurrent);
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::CheckInStreakExtended, 9),
                new StreakEvent(StreakEventType::UnderLimitStreakBroken, 5),
            ],
            $advance->events,
        );
    }

    public function test_FR_GAME_03_over_the_limit_without_an_under_limit_streak_reports_nothing_broken(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 8, underLimit: 0), self::over());

        self::assertSame(0, $advance->state->underLimitCurrent);
        self::assertNull($advance->event(StreakEventType::UnderLimitStreakBroken));
    }

    public function test_FR_GAME_03_missed_day_without_a_freeze_resets_the_under_limit_streak(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 8, underLimit: 5), self::missed());

        self::assertSame(0, $advance->state->underLimitCurrent);
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::CheckInStreakBroken, 8),
                new StreakEvent(StreakEventType::UnderLimitStreakBroken, 5),
            ],
            $advance->events,
        );
    }

    public function test_FR_GAME_03_missed_day_with_few_logs_does_not_count_as_under_the_limit(): void
    {
        $advance = $this->calculator->advance(
            self::state(checkIn: 8, underLimit: 5),
            DayOutcome::missed(count: 0, limit: 5),
        );

        self::assertSame(0, $advance->state->underLimitCurrent);
        self::assertNull($advance->event(StreakEventType::UnderLimitStreakExtended));
    }

    public function test_FR_GAME_03_frozen_day_resets_the_under_limit_streak_and_reports_it_after_the_freeze(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 8, underLimit: 5, freezes: 1), self::missed());

        self::assertSame(0, $advance->state->underLimitCurrent);
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::FreezeUsed, 0),
                new StreakEvent(StreakEventType::UnderLimitStreakBroken, 5),
            ],
            $advance->events,
        );
    }

    public function test_FR_GAME_03_frozen_day_ends_an_under_limit_streak_of_a_single_day(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 8, underLimit: 1, freezes: 2), self::missed());

        self::assertEquals(new StreakState(8, 8, 0, 1, 1), $advance->state);
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::FreezeUsed, 1),
                new StreakEvent(StreakEventType::UnderLimitStreakBroken, 1),
            ],
            $advance->events,
        );
    }

    public function test_FR_GAME_03_frozen_day_without_an_under_limit_streak_reports_only_the_freeze_used(): void
    {
        $advance = $this->calculator->advance(
            self::state(checkIn: 8, underLimit: 0, underLimitLongest: 6, freezes: 2),
            self::missed(),
        );

        self::assertEquals(new StreakState(8, 8, 0, 6, 1), $advance->state);
        self::assertEquals([new StreakEvent(StreakEventType::FreezeUsed, 1)], $advance->events);
    }

    public function test_FR_GAME_03_frozen_day_keeps_the_longest_under_limit_streak(): void
    {
        $running = self::state(checkIn: 9, checkInLongest: 20, underLimit: 4, underLimitLongest: 15, freezes: 1);
        $personalBest = self::state(checkIn: 9, underLimit: 9, underLimitLongest: 9, freezes: 1);

        $afterRunning = $this->calculator->advance($running, self::missed())->state;
        $afterPersonalBest = $this->calculator->advance($personalBest, self::missed())->state;

        self::assertEquals(new StreakState(9, 20, 0, 15, 0), $afterRunning);
        self::assertEquals(new StreakState(9, 9, 0, 9, 0), $afterPersonalBest);
    }

    public function test_FR_GAME_03_frozen_day_resets_the_under_limit_streak_whatever_was_logged_on_it(): void
    {
        // The count of a day without a check-in is not looked at: 12 logged against a limit of 8,
        // exactly the limit, or nothing at all give the same result. An unconfirmed day is never an
        // under-limit day.
        $state = self::state(checkIn: 9, underLimit: 4, freezes: 1);

        $overLimit = $this->calculator->advance($state, DayOutcome::missed(count: 12, limit: 8));
        $atLimit = $this->calculator->advance($state, DayOutcome::missed(count: 8, limit: 8));
        $nothingLogged = $this->calculator->advance($state, DayOutcome::missed(count: 0, limit: 8));

        self::assertEquals(new StreakState(9, 9, 0, 4, 0), $overLimit->state);
        self::assertEquals($overLimit, $atLimit);
        self::assertEquals($overLimit, $nothingLogged);
    }

    /**
     * The loophole the freeze used to open: on a heavy day, skipping the check-in while holding a
     * freeze kept the under-limit streak, and the honest check-in lost it. Now both lose it, and the
     * honest check-in is the better choice in every counter.
     */
    #[DataProvider('freezesHeldOnAHeavyDay')]
    public function test_FR_GAME_03_skipping_the_check_in_on_a_heavy_day_saves_no_more_than_the_honest_check_in(
        int $freezes,
    ): void {
        $state = self::state(checkIn: 9, checkInLongest: 20, underLimit: 4, underLimitLongest: 11, freezes: $freezes);

        $honest = $this->calculator->advance($state, DayOutcome::checkedIn(count: 12, limit: 8));
        $skipped = $this->calculator->advance($state, DayOutcome::missed(count: 12, limit: 8));

        self::assertSame(0, $honest->state->underLimitCurrent);
        self::assertSame(0, $skipped->state->underLimitCurrent);
        self::assertSame($honest->state->underLimitLongest, $skipped->state->underLimitLongest);
        $broken = new StreakEvent(StreakEventType::UnderLimitStreakBroken, 4);
        self::assertEquals($broken, $honest->event(StreakEventType::UnderLimitStreakBroken));
        self::assertEquals($broken, $skipped->event(StreakEventType::UnderLimitStreakBroken));
        // Skipping gains nothing anywhere else either: a shorter check-in streak and no freeze more.
        self::assertSame(10, $honest->state->checkInCurrent);
        self::assertLessThan($honest->state->checkInCurrent, $skipped->state->checkInCurrent);
        self::assertLessThanOrEqual($honest->state->freezesHeld, $skipped->state->freezesHeld);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function freezesHeldOnAHeavyDay(): iterable
    {
        yield 'no freeze' => [0];
        yield 'one freeze' => [1];
        yield 'two freezes' => [2];
    }

    public function test_FR_GAME_03_under_limit_streak_restarts_at_1_after_a_frozen_day(): void
    {
        $advances = $this->simulate(self::state(checkIn: 9, underLimit: 4, freezes: 1), 'MUU');

        self::assertEquals(new StreakState(9, 9, 0, 4, 0), $advances[0]->state);
        self::assertEquals(new StreakState(10, 10, 1, 4, 0), $advances[1]->state);
        self::assertEquals(new StreakState(11, 11, 2, 4, 0), $advances[2]->state);
        self::assertSame([1, 2], self::valuesOf($advances, StreakEventType::UnderLimitStreakExtended));
    }

    public function test_FR_GAME_03_longest_under_limit_streak_grows_with_a_new_personal_best(): void
    {
        $advance = $this->calculator->advance(
            self::state(checkIn: 9, underLimit: 4, underLimitLongest: 4),
            self::under(),
        );

        self::assertSame(5, $advance->state->underLimitLongest);
    }

    public function test_FR_GAME_03_longest_under_limit_streak_never_decreases(): void
    {
        $state = self::state(checkIn: 9, checkInLongest: 20, underLimit: 2, underLimitLongest: 15);

        self::assertSame(15, $this->calculator->advance($state, self::under())->state->underLimitLongest);
        self::assertSame(15, $this->calculator->advance($state, self::over())->state->underLimitLongest);
        self::assertSame(15, $this->calculator->advance($state, self::missed())->state->underLimitLongest);
        self::assertSame(
            15,
            $this->calculator->advance(
                self::state(checkIn: 9, checkInLongest: 20, underLimit: 2, underLimitLongest: 15, freezes: 2),
                self::missed(),
            )->state->underLimitLongest,
        );
    }

    public function test_FR_GAME_03_under_limit_streak_restarts_after_an_over_limit_day(): void
    {
        $advances = $this->simulate(StreakState::initial(), 'UUUOUU');

        self::assertEquals(new StreakState(6, 6, 2, 3, 0), self::last($advances)->state);
    }

    // ---- FR-GAME-04: streak freezes ----

    #[DataProvider('freezeBoundaries')]
    public function test_FR_GAME_04_freeze_is_earned_when_the_check_in_streak_reaches_a_multiple_of_7(
        int $streakBefore,
        bool $earned,
    ): void {
        $advance = $this->calculator->advance(self::state(checkIn: $streakBefore, freezes: 0), self::over());

        self::assertSame($earned ? 1 : 0, $advance->state->freezesHeld);
        self::assertEquals(
            $earned ? new StreakEvent(StreakEventType::FreezeEarned, 1) : null,
            $advance->event(StreakEventType::FreezeEarned),
        );
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function freezeBoundaries(): iterable
    {
        yield '0 → 1' => [0, false];
        yield '5 → 6' => [5, false];
        yield '6 → 7' => [6, true];
        yield '7 → 8' => [7, false];
        yield '12 → 13' => [12, false];
        yield '13 → 14' => [13, true];
        yield '14 → 15' => [14, false];
        yield '29 → 30' => [29, false];
        yield '34 → 35' => [34, true];
        yield '363 → 364' => [363, true];
    }

    public function test_FR_GAME_04_second_freeze_fills_the_cap_of_2(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 13, freezes: 1), self::under());

        self::assertSame(2, $advance->state->freezesHeld);
        self::assertEquals(
            new StreakEvent(StreakEventType::FreezeEarned, 2),
            $advance->event(StreakEventType::FreezeEarned),
        );
    }

    public function test_FR_GAME_04_freeze_earned_while_holding_2_is_lost(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 20, freezes: 2), self::under());

        self::assertSame(2, $advance->state->freezesHeld);
        self::assertNull($advance->event(StreakEventType::FreezeEarned));
        self::assertEquals(
            new StreakEvent(StreakEventType::FreezeLost, 2),
            $advance->event(StreakEventType::FreezeLost),
        );
    }

    public function test_FR_GAME_04_missed_day_uses_one_freeze_keeps_the_check_in_streak_and_resets_the_under_limit_streak(): void
    {
        $state = self::state(checkIn: 9, checkInLongest: 30, underLimit: 4, underLimitLongest: 11, freezes: 2);

        $advance = $this->calculator->advance($state, self::missed());

        // A freeze protects the check-in streak only. The under-limit streak ends, its longest value stays.
        self::assertEquals(new StreakState(9, 30, 0, 11, 1), $advance->state);
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::FreezeUsed, 1),
                new StreakEvent(StreakEventType::UnderLimitStreakBroken, 4),
            ],
            $advance->events,
        );
    }

    public function test_FR_GAME_04_frozen_day_does_not_report_the_check_in_streak_extended_or_broken(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 9, underLimit: 4, freezes: 1), self::missed());

        self::assertSame(9, $advance->state->checkInCurrent);
        self::assertNull($advance->event(StreakEventType::CheckInStreakExtended));
        self::assertNull($advance->event(StreakEventType::CheckInStreakBroken));
    }

    public function test_FR_GAME_04_last_freeze_is_used_before_the_check_in_streak_breaks(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 9, underLimit: 4, freezes: 1), self::missed());

        self::assertEquals(new StreakState(9, 9, 0, 4, 0), $advance->state);
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::FreezeUsed, 0),
                new StreakEvent(StreakEventType::UnderLimitStreakBroken, 4),
            ],
            $advance->events,
        );
    }

    public function test_FR_GAME_04_freeze_protects_the_check_in_streak_even_without_an_under_limit_streak(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 9, underLimit: 0, freezes: 1), self::missed());

        self::assertSame(9, $advance->state->checkInCurrent);
        self::assertSame(0, $advance->state->freezesHeld);
        self::assertEquals([new StreakEvent(StreakEventType::FreezeUsed, 0)], $advance->events);
    }

    public function test_FR_GAME_04_missed_day_without_a_freeze_resets_both_streaks_to_zero(): void
    {
        $advance = $this->calculator->advance(
            self::state(checkIn: 9, checkInLongest: 30, underLimit: 4, underLimitLongest: 11, freezes: 0),
            self::missed(),
        );

        self::assertEquals(new StreakState(0, 30, 0, 11, 0), $advance->state);
    }

    public function test_FR_GAME_04_two_freezes_cover_two_missed_days_and_the_third_breaks_the_check_in_streak(): void
    {
        $advances = $this->simulate(self::state(checkIn: 14, underLimit: 14, freezes: 2), 'MMM');

        // The under-limit streak ends on the first frozen day and is reported broken once, there.
        self::assertEquals(new StreakState(14, 14, 0, 14, 1), $advances[0]->state);
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::FreezeUsed, 1),
                new StreakEvent(StreakEventType::UnderLimitStreakBroken, 14),
            ],
            $advances[0]->events,
        );
        self::assertEquals(new StreakState(14, 14, 0, 14, 0), $advances[1]->state);
        self::assertEquals([new StreakEvent(StreakEventType::FreezeUsed, 0)], $advances[1]->events);
        self::assertEquals(new StreakState(0, 14, 0, 14, 0), $advances[2]->state);
        self::assertEquals([new StreakEvent(StreakEventType::CheckInStreakBroken, 14)], $advances[2]->events);
    }

    public function test_FR_GAME_04_missed_day_right_after_earning_a_freeze_spends_that_freeze(): void
    {
        $advances = $this->simulate(StreakState::initial(), 'UUUUUUUMU');

        self::assertSame(1, $advances[6]->state->freezesHeld);
        self::assertEquals(new StreakState(7, 7, 0, 7, 0), $advances[7]->state);
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::FreezeUsed, 0),
                new StreakEvent(StreakEventType::UnderLimitStreakBroken, 7),
            ],
            $advances[7]->events,
        );
        self::assertEquals(new StreakState(8, 8, 1, 7, 0), $advances[8]->state);
    }

    public function test_FR_GAME_04_frozen_day_on_a_multiple_of_7_does_not_earn_another_freeze(): void
    {
        // The streak stays at 14 through the frozen days. It did not "reach" 14 again.
        $advances = $this->simulate(self::state(checkIn: 14, freezes: 2), 'MMU');

        self::assertSame([1, 0, 0], self::freezesPerDay($advances));
        self::assertSame(15, self::last($advances)->state->checkInCurrent);
        self::assertSame([], self::valuesOf($advances, StreakEventType::FreezeEarned));
    }

    public function test_FR_GAME_04_freeze_is_earned_again_each_time_a_rebuilt_streak_reaches_7(): void
    {
        $advances = $this->simulate(self::state(checkIn: 0, checkInLongest: 50), 'OOOOOOO');

        self::assertSame(1, self::last($advances)->state->freezesHeld);
        self::assertSame([1], self::valuesOf($advances, StreakEventType::FreezeEarned));
    }

    public function test_FR_GAME_04_freeze_is_not_spent_when_there_is_no_streak_to_protect(): void
    {
        $state = self::state(checkIn: 0, checkInLongest: 9, freezes: 1);

        $advance = $this->calculator->advance($state, self::missed());

        self::assertEquals($state, $advance->state);
        self::assertSame([], $advance->events);
    }

    /**
     * The calculator keeps a freeze when there is no streak to protect. This shows that the question
     * never comes up for real users: no sequence of days leads to "streak 0 while holding a freeze".
     */
    #[DataProvider('freezeRules')]
    public function test_FR_GAME_04_streak_zero_with_a_freeze_is_unreachable_from_a_new_user(StreakRules $rules): void
    {
        $calculator = new StreakCalculator($rules);
        $days = [self::under(), self::over(), self::missed()];
        // The transitions depend on the current streaks and the freezes only, so that triple names a state.
        $key = static fn (StreakState $state): string
            => "{$state->checkInCurrent}/{$state->underLimitCurrent}/{$state->freezesHeld}";

        $seen = [$key(StreakState::initial()) => true];
        $frontier = [StreakState::initial()];
        for ($depth = 1; $depth <= 45; $depth++) {
            $next = [];
            foreach ($frontier as $state) {
                foreach ($days as $day) {
                    $after = $calculator->advance($state, $day)->state;

                    self::assertFalse(
                        $after->checkInCurrent === 0 && $after->freezesHeld > 0,
                        "streak 0 with {$after->freezesHeld} freeze(s) after {$depth} days",
                    );
                    self::assertLessThanOrEqual($rules->maxFreezesHeld, $after->freezesHeld);
                    // FR-GAME-03: only a checked-in day at or under the limit keeps the under-limit
                    // streak alive. Skipped days end it like over-limit days, with or without a freeze.
                    self::assertSame(
                        $day->isUnderLimit() ? $state->underLimitCurrent + 1 : 0,
                        $after->underLimitCurrent,
                        "under-limit streak after {$depth} days",
                    );
                    if (!$day->checkedIn) {
                        // FR-GAME-04: a freeze is used exactly when there is one, and it keeps the
                        // check-in streak. Longest values survive every missed day.
                        $frozen = $state->freezesHeld > 0;
                        self::assertSame($frozen ? $state->checkInCurrent : 0, $after->checkInCurrent);
                        self::assertSame($frozen ? $state->freezesHeld - 1 : 0, $after->freezesHeld);
                        self::assertSame($state->checkInLongest, $after->checkInLongest);
                        self::assertSame($state->underLimitLongest, $after->underLimitLongest);
                    }
                    if (!isset($seen[$key($after)])) {
                        $seen[$key($after)] = true;
                        $next[] = $after;
                    }
                }
            }
            $frontier = $next;
        }

        // Guards the search itself: it must have walked through many different states.
        self::assertGreaterThan(1000, count($seen));
    }

    /**
     * @return iterable<string, array{StreakRules}>
     */
    public static function freezeRules(): iterable
    {
        yield 'v1: every 7 days, at most 2' => [new StreakRules()];
        yield 'every day, at most 3' => [new StreakRules(freezeInterval: 1, maxFreezesHeld: 3)];
        yield 'every 3 days, at most 1' => [new StreakRules(freezeInterval: 3, maxFreezesHeld: 1)];
    }

    public function test_FR_GAME_04_state_holding_more_freezes_than_the_cap_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->advance(self::state(checkIn: 20, freezes: 3), self::under());
    }

    public function test_FR_GAME_04_state_holding_exactly_the_cap_is_accepted(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 15, freezes: 2), self::under());

        self::assertSame(2, $advance->state->freezesHeld);
    }

    public function test_FR_GAME_04_freezes_are_earned_capped_and_used_over_a_long_sequence(): void
    {
        $days = str_repeat('U', 7)   // days 1-7: streak 7, first freeze
            . 'M'                    // day 8: freeze used, under-limit streak ends at 7
            . str_repeat('U', 21)    // days 9-29: streak 8 to 28 (under limit 1 to 21), freezes at 14 and 21, lost at 28
            . 'MMM'                  // days 30-32: two freezes used, under-limit streak ends at 21, then the check-in streak breaks
            . str_repeat('U', 30);   // days 33-62: rebuilt to 30, freezes at 7 and 14, lost at 21 and 28

        $advances = $this->simulate(StreakState::initial(), $days);
        $onDay = static fn (int $day): StreakAdvance => $advances[$day - 1];

        self::assertEquals(new StreakState(7, 7, 7, 7, 1), $onDay(7)->state);
        self::assertEquals(new StreakState(7, 7, 0, 7, 0), $onDay(8)->state);
        // Check-in streak day 8 to 14 on days 9 to 15: seven under-limit days since the frozen day.
        self::assertEquals(new StreakState(14, 14, 7, 7, 1), $onDay(15)->state);
        self::assertEquals(new StreakState(21, 21, 14, 14, 2), $onDay(22)->state);
        self::assertEquals(new StreakState(28, 28, 21, 21, 2), $onDay(29)->state);
        self::assertEquals(new StreakState(28, 28, 0, 21, 1), $onDay(30)->state);
        self::assertEquals(new StreakState(28, 28, 0, 21, 0), $onDay(31)->state);
        self::assertEquals(new StreakState(0, 28, 0, 21, 0), $onDay(32)->state);
        self::assertEquals(new StreakState(7, 28, 7, 21, 1), $onDay(39)->state);
        // 30 under-limit days in a row beat the old best of 21.
        self::assertEquals(new StreakState(30, 30, 30, 30, 2), $onDay(62)->state);

        self::assertSame([1, 1, 2, 1, 2], self::valuesOf($advances, StreakEventType::FreezeEarned));
        self::assertSame([2, 2, 2], self::valuesOf($advances, StreakEventType::FreezeLost));
        self::assertSame([0, 1, 0], self::valuesOf($advances, StreakEventType::FreezeUsed));
        self::assertSame([28], self::valuesOf($advances, StreakEventType::CheckInStreakBroken));
        // Broken on the frozen days 8 and 30. Days 31 and 32 have no under-limit streak left to break.
        self::assertSame([7, 21], self::valuesOf($advances, StreakEventType::UnderLimitStreakBroken));
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::FreezeUsed, 1),
                new StreakEvent(StreakEventType::UnderLimitStreakBroken, 21),
            ],
            $onDay(30)->events,
        );
        self::assertEquals([new StreakEvent(StreakEventType::FreezeUsed, 0)], $onDay(31)->events);
        self::assertEquals([new StreakEvent(StreakEventType::CheckInStreakBroken, 28)], $onDay(32)->events);
        self::assertSame([7, 30], self::valuesOf($advances, StreakEventType::CheckInStreakMilestoneReached));
        self::assertNotNull($onDay(29)->event(StreakEventType::FreezeLost));
        self::assertNotNull($onDay(62)->event(StreakEventType::CheckInStreakMilestoneReached));
    }

    public function test_FR_GAME_04_freeze_numbers_come_from_the_rules(): void
    {
        $calculator = new StreakCalculator(new StreakRules(freezeInterval: 3, maxFreezesHeld: 1));
        $state = StreakState::initial();
        $freezes = [];
        for ($day = 1; $day <= 6; $day++) {
            $state = $calculator->advance($state, self::under())->state;
            $freezes[] = $state->freezesHeld;
        }

        self::assertSame([0, 0, 1, 1, 1, 1], $freezes);
    }

    public function test_FR_GAME_04_no_freeze_is_earned_or_reported_lost_when_freezes_are_switched_off(): void
    {
        $calculator = new StreakCalculator(new StreakRules(maxFreezesHeld: 0));

        $advance = $calculator->advance(self::state(checkIn: 6), self::over());

        self::assertSame(0, $advance->state->freezesHeld);
        self::assertEquals(
            [
                new StreakEvent(StreakEventType::CheckInStreakExtended, 7),
                new StreakEvent(StreakEventType::CheckInStreakMilestoneReached, 7),
            ],
            $advance->events,
        );
    }

    // ---- Milestones: the first-time-only XP of FR-GAME-01 (Appendix A). Badges are not built yet. ----

    #[DataProvider('milestones')]
    public function test_FR_GAME_01_check_in_streak_milestone_is_reported_at_7_30_100_and_365_days(
        int $threshold,
    ): void {
        $before = $threshold - 1;

        $advance = $this->calculator->advance(self::state(checkIn: $before, checkInLongest: $before), self::over());

        self::assertEquals(
            new StreakEvent(StreakEventType::CheckInStreakMilestoneReached, $threshold),
            $advance->event(StreakEventType::CheckInStreakMilestoneReached),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function milestones(): iterable
    {
        yield '6 → 7' => [7];
        yield '29 → 30' => [30];
        yield '99 → 100' => [100];
        yield '364 → 365' => [365];
    }

    #[DataProvider('nonMilestones')]
    public function test_FR_GAME_01_no_milestone_is_reported_between_the_thresholds(int $streakBefore): void
    {
        $advance = $this->calculator->advance(
            self::state(checkIn: $streakBefore, checkInLongest: $streakBefore),
            self::under(),
        );

        self::assertNull($advance->event(StreakEventType::CheckInStreakMilestoneReached));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonMilestones(): iterable
    {
        yield '0 → 1' => [0];
        yield '5 → 6' => [5];
        yield '7 → 8' => [7];
        yield '13 → 14' => [13];
        yield '28 → 29' => [28];
        yield '30 → 31' => [30];
        yield '365 → 366' => [365];
    }

    public function test_FR_GAME_01_milestone_day_reports_its_events_in_a_fixed_order(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 6, underLimit: 2), self::under());

        self::assertEquals(
            [
                new StreakEvent(StreakEventType::CheckInStreakExtended, 7),
                new StreakEvent(StreakEventType::CheckInStreakMilestoneReached, 7),
                new StreakEvent(StreakEventType::FreezeEarned, 1),
                new StreakEvent(StreakEventType::UnderLimitStreakExtended, 3),
            ],
            $advance->events,
        );
    }

    public function test_FR_GAME_01_milestone_is_not_reported_again_when_a_broken_streak_is_rebuilt(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 6, checkInLongest: 7), self::under());

        self::assertSame(7, $advance->state->checkInCurrent);
        self::assertNull($advance->event(StreakEventType::CheckInStreakMilestoneReached));
    }

    public function test_FR_GAME_01_milestone_below_an_older_personal_best_is_never_reported(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 29, checkInLongest: 200), self::under());

        self::assertNull($advance->event(StreakEventType::CheckInStreakMilestoneReached));
    }

    public function test_FR_GAME_01_rebuilt_streak_reports_the_next_milestone_once_it_beats_the_personal_best(): void
    {
        $advance = $this->calculator->advance(self::state(checkIn: 29, checkInLongest: 29), self::under());

        self::assertEquals(
            new StreakEvent(StreakEventType::CheckInStreakMilestoneReached, 30),
            $advance->event(StreakEventType::CheckInStreakMilestoneReached),
        );
    }

    public function test_FR_GAME_01_each_milestone_is_reported_exactly_once_across_breaks(): void
    {
        // 7 days, break, 7 days, break, 30 days, break, 30 days.
        $days = str_repeat('U', 7) . 'MM' . str_repeat('U', 7) . 'MM' . str_repeat('O', 30) . 'MMM' . str_repeat('U', 30);

        $advances = $this->simulate(StreakState::initial(), $days);

        self::assertSame([7, 30], self::valuesOf($advances, StreakEventType::CheckInStreakMilestoneReached));
        self::assertSame([7, 7, 30], self::valuesOf($advances, StreakEventType::CheckInStreakBroken));
    }

    public function test_FR_GAME_01_milestone_is_still_reported_after_a_frozen_day(): void
    {
        $advances = $this->simulate(self::state(checkIn: 29, checkInLongest: 29, freezes: 1), 'MU');

        self::assertSame([30], self::valuesOf($advances, StreakEventType::CheckInStreakMilestoneReached));
    }

    public function test_FR_GAME_01_milestone_thresholds_come_from_the_rules(): void
    {
        $calculator = new StreakCalculator(new StreakRules(milestoneThresholds: [2, 5]));
        $state = StreakState::initial();
        $milestones = [];
        for ($day = 1; $day <= 7; $day++) {
            $advance = $calculator->advance($state, self::under());
            $state = $advance->state;
            $milestones[] = $advance->event(StreakEventType::CheckInStreakMilestoneReached)?->value;
        }

        self::assertSame([null, 2, null, null, 5, null, null], $milestones);
    }

    // ---- NFR-04: determinism ----

    public function test_NFR_04_same_state_and_day_give_the_same_result_every_time(): void
    {
        $state = self::state(checkIn: 6, checkInLongest: 6, underLimit: 3, underLimitLongest: 5, freezes: 1);

        foreach ([self::under(), self::over(), self::missed()] as $day) {
            self::assertEquals(
                $this->calculator->advance($state, $day),
                $this->calculator->advance($state, $day),
            );
        }
    }

    public function test_NFR_04_replaying_the_same_days_gives_identical_states_and_events(): void
    {
        $days = 'UUOUUUUMUUUUUUUOMMMUUOUUUUUUUUMU';

        $first = $this->simulate(StreakState::initial(), $days);
        $second = $this->simulate(StreakState::initial(), $days);
        $otherInstance = new StreakCalculator(new StreakRules());
        $state = StreakState::initial();
        foreach (str_split($days) as $index => $code) {
            $advance = $otherInstance->advance($state, self::day($code));
            self::assertEquals($first[$index], $advance);
            $state = $advance->state;
        }

        self::assertEquals($first, $second);
    }

    public function test_NFR_04_advancing_does_not_change_the_state_passed_in(): void
    {
        $state = self::state(checkIn: 6, underLimit: 3, freezes: 1);
        $copy = clone $state;

        $this->calculator->advance($state, self::under());
        $this->calculator->advance($state, self::missed());

        self::assertEquals($copy, $state);
    }

    // ---- helpers ----

    /**
     * Builds a valid state from the interesting numbers. Longest values default to the current ones.
     */
    private static function state(
        int $checkIn,
        ?int $checkInLongest = null,
        int $underLimit = 0,
        ?int $underLimitLongest = null,
        int $freezes = 0,
    ): StreakState {
        return new StreakState(
            $checkIn,
            $checkInLongest ?? $checkIn,
            $underLimit,
            $underLimitLongest ?? $underLimit,
            $freezes,
        );
    }

    private static function under(): DayOutcome
    {
        return DayOutcome::checkedIn(count: 3, limit: 5);
    }

    private static function over(): DayOutcome
    {
        return DayOutcome::checkedIn(count: 8, limit: 5);
    }

    private static function missed(): DayOutcome
    {
        return DayOutcome::missed(count: 2, limit: 5);
    }

    private static function day(string $code): DayOutcome
    {
        return match ($code) {
            'U' => self::under(),
            'O' => self::over(),
            'M' => self::missed(),
            default => throw new InvalidArgumentException("Unknown day code {$code}."),
        };
    }

    /**
     * @return list<StreakAdvance> One entry per day, in order.
     */
    private function simulate(StreakState $state, string $days): array
    {
        $advances = [];
        foreach (str_split($days) as $code) {
            $advance = $this->calculator->advance($state, self::day($code));
            $advances[] = $advance;
            $state = $advance->state;
        }

        return $advances;
    }

    /**
     * @param list<StreakAdvance> $advances
     */
    private static function last(array $advances): StreakAdvance
    {
        if ($advances === []) {
            throw new LogicException('No days were simulated.');
        }

        return $advances[count($advances) - 1];
    }

    /**
     * @param list<StreakAdvance> $advances
     *
     * @return list<int>
     */
    private static function freezesPerDay(array $advances): array
    {
        return array_map(static fn (StreakAdvance $advance): int => $advance->state->freezesHeld, $advances);
    }

    /**
     * The values of all events of one type, in the order they happened.
     *
     * @param list<StreakAdvance> $advances
     *
     * @return list<int|null>
     */
    private static function valuesOf(array $advances, StreakEventType $type): array
    {
        $values = [];
        foreach ($advances as $advance) {
            foreach ($advance->events as $event) {
                if ($event->type === $type) {
                    $values[] = $event->value;
                }
            }
        }

        return $values;
    }
}
