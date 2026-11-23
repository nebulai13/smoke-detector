<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Day;

use LogicException;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\CheckInResult;
use SmokeDetector\Application\Day\DayNotOpen;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Application\Logging\Rejection;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * POST /days/{date}/check-in (HLD §4.6, FR-LOG-04, FR-GAME-06): "Done for today". The check-in is
 * stored, and the answer shows at once what the day gives: the celebration events and the streaks,
 * XP and level as they will be if nothing changes any more. Stored progress changes only when the
 * day becomes final.
 */
final class CheckInTest extends LoggingUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_LOG_04_a_check_in_is_stored_and_answered_with_the_day_and_its_celebration(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $this->givenDay($this->anna, '2026-11-17', 3);

        $result = $this->checkInOn('2026-11-17');

        self::assertSame(['2026-11-17', 3, 9, 6, true, false], self::view($result->day));
        self::assertSame(['checked_in', 'under_limit_day:1', 'streak_extended:1'], self::events($result->events));
        // 10 XP for the check-in and 20 for staying under the limit.
        self::assertSame([[1, 1, 1, 1, 0], 30, [0, 30, 100], true], self::progress($result->progress));
        self::assertEquals([new DayRecord($this->anna->id, self::date('2026-11-17'), 3, $this->now())], $this->dayRecords->ofUser($this->anna->id));
    }

    public function test_FR_GAME_06_stored_progress_and_xp_do_not_change_before_the_day_is_final(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $before = $this->progressRecords->find($this->anna->id);

        $this->checkInOn('2026-11-17');

        self::assertEquals($before, $this->progressRecords->find($this->anna->id));
        self::assertSame([], $this->xpLedger->ofUser($this->anna->id));
    }

    public function test_FR_GAME_06_a_day_without_any_log_is_checked_in_as_smoke_free(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');

        $result = $this->checkInOn('2026-11-17');

        self::assertSame(['2026-11-17', 0, 9, 9, true, false], self::view($result->day));
        self::assertSame(['checked_in', 'under_limit_day:1', 'smoke_free_day', 'streak_extended:1'], self::events($result->events));
        self::assertSame([[1, 1, 1, 1, 0], 70, [0, 70, 100], true], self::progress($result->progress));
        self::assertEquals([new DayRecord($this->anna->id, self::date('2026-11-17'), 0, $this->now())], $this->dayRecords->ofUser($this->anna->id));
    }

    public function test_FR_LOG_06_a_day_over_the_limit_is_celebrated_for_the_check_in_and_blamed_for_nothing(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $this->givenDay($this->anna, '2026-11-17', 12);

        $result = $this->checkInOn('2026-11-17');

        self::assertSame(['2026-11-17', 12, 9, 0, true, false], self::view($result->day));
        self::assertSame(['checked_in', 'streak_extended:1'], self::events($result->events));
        self::assertSame([[1, 1, 0, 0, 0], 10, [0, 10, 100], true], self::progress($result->progress));
    }

    public function test_FR_LOG_04_a_second_check_in_changes_nothing_and_gets_the_same_answer(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $first = $this->checkInOn('2026-11-17');
        $this->clock->advanceSeconds(3600);

        $second = $this->checkInOn('2026-11-17');

        self::assertEquals($first, $second);
        self::assertEquals(
            [new DayRecord($this->anna->id, self::date('2026-11-17'), 0, TestData::time('2026-11-17 12:00:00'))],
            $this->dayRecords->ofUser($this->anna->id),
        );
    }

    public function test_FR_LOG_04_yesterday_can_be_checked_in_while_it_is_not_final(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-16', streaks: [5, 5, 5, 5, 0], totalXp: 150, level: 1);
        $this->givenDay($this->anna, '2026-11-16', 2);

        $result = $this->checkInOn('2026-11-16');

        self::assertSame(['2026-11-16', 2, 9, 7, true, false], self::view($result->day));
        self::assertSame(['checked_in', 'under_limit_day:6', 'streak_extended:6'], self::events($result->events));
        // Today is not checked in and not over: it is not in the projection yet.
        self::assertSame([[6, 6, 6, 6, 0], 180, [1, 80, 200], true], self::progress($result->progress));
        self::assertEquals([new DayRecord($this->anna->id, self::date('2026-11-16'), 2, $this->now())], $this->dayRecords->ofUser($this->anna->id));
    }

    public function test_FR_GAME_04_the_celebration_shows_milestone_freeze_and_level_in_the_order_they_are_played(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-17', streaks: [6, 6, 6, 6, 0], totalXp: 180, level: 1);

        $result = $this->checkInOn('2026-11-17');

        self::assertSame(
            ['checked_in', 'under_limit_day:7', 'smoke_free_day', 'streak_extended:7', 'streak_milestone:7', 'freeze_earned:1', 'level_up:2'],
            self::events($result->events),
        );
        // 10 + 20 + 40 for the day and 50 for the milestone: 300 XP is exactly level 2.
        self::assertSame([[7, 7, 7, 7, 1], 300, [2, 0, 300], true], self::progress($result->progress));
    }

    public function test_FR_GAME_02_an_open_day_that_was_missed_counts_as_missed_until_it_is_checked_in_after_all(): void
    {
        // The 15th and the 16th are over and not checked in.
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15', streaks: [3, 3, 3, 3, 0], totalXp: 300, level: 2);

        $today = $this->checkInOn('2026-11-17');

        // The events are those of the 17th alone. That the streak broke belongs to the 15th.
        self::assertSame(['checked_in', 'under_limit_day:1', 'smoke_free_day', 'streak_extended:1'], self::events($today->events));
        self::assertSame([[1, 3, 1, 3, 0], 370, [2, 70, 300], true], self::progress($today->progress));

        $yesterday = $this->checkInOn('2026-11-16');

        self::assertSame(['checked_in', 'under_limit_day:1', 'smoke_free_day', 'streak_extended:1'], self::events($yesterday->events));
        self::assertSame([[2, 3, 2, 3, 0], 440, [2, 140, 300], true], self::progress($yesterday->progress));

        $theDayBefore = $this->checkInOn('2026-11-15');

        // Now no day is missed any more: the stored streak of 3 simply goes on.
        self::assertSame(['checked_in', 'under_limit_day:4', 'smoke_free_day', 'streak_extended:4'], self::events($theDayBefore->events));
        self::assertSame([[6, 6, 6, 6, 0], 510, [2, 210, 300], true], self::progress($theDayBefore->progress));
    }

    public function test_FR_GAME_04_a_freeze_covers_a_missed_open_day_in_the_projection(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-16', streaks: [8, 8, 8, 8, 1], totalXp: 1000, level: 4);

        $result = $this->checkInOn('2026-11-17');

        // The freeze kept the check-in streak over the 16th. The under-limit streak began again.
        self::assertSame(['checked_in', 'under_limit_day:1', 'smoke_free_day', 'streak_extended:9'], self::events($result->events));
        self::assertSame([[9, 9, 1, 8, 0], 1070, [4, 70, 500], true], self::progress($result->progress));
    }

    public function test_FR_LOG_04_a_day_that_is_final_or_in_the_future_or_before_the_plan_cannot_be_checked_in(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');

        $refused = [];
        foreach (['2026-11-09', '2026-11-10', '2026-11-14', '2026-11-18', '2027-11-17'] as $date) {
            try {
                $this->checkInOn($date);
                self::fail("Expected the check-in for {$date} to be refused.");
            } catch (DayNotOpen $notOpen) {
                $refused[$date] = $notOpen->reason;
            }
        }

        self::assertSame(
            [
                '2026-11-09' => Rejection::BeforeStart,
                '2026-11-10' => Rejection::DayFinalized,
                '2026-11-14' => Rejection::DayFinalized,
                '2026-11-18' => Rejection::InFuture,
                '2027-11-17' => Rejection::InFuture,
            ],
            $refused,
        );
        self::assertSame([], $this->dayRecords->ofUser($this->anna->id));
    }

    public function test_FR_LOG_04_every_open_day_can_be_checked_in_from_the_first_to_today(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');

        foreach (['2026-11-15', '2026-11-16', '2026-11-17'] as $date) {
            self::assertTrue($this->checkInOn($date)->day->checkedIn);
        }
    }

    public function test_FR_I18N_02_today_is_the_users_local_day(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        // One second before midnight in Vienna.
        $this->clock->travelTo(TestData::time('2026-11-17 22:59:59'));

        try {
            $this->checkInOn('2026-11-18');
            self::fail('The 18th has not begun in Vienna.');
        } catch (DayNotOpen $notOpen) {
            self::assertSame(Rejection::InFuture, $notOpen->reason);
        }

        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));

        self::assertSame(['2026-11-18', 0, 9, 9, true, false], self::view($this->checkInOn('2026-11-18')->day));
    }

    public function test_FR_I18N_02_a_time_zone_that_counts_from_tomorrow_does_not_change_what_today_is(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        // In Tokyo it is the 18th already. The change counts from the 18th: today is still the 17th.
        $this->givenZoneChange($this->anna, '2026-11-18', 'Asia/Tokyo');

        try {
            $this->checkInOn('2026-11-18');
            self::fail('The 18th has not begun for Anna.');
        } catch (DayNotOpen $notOpen) {
            self::assertSame(Rejection::InFuture, $notOpen->reason);
        }

        self::assertTrue($this->checkInOn('2026-11-17')->day->checkedIn);
    }

    public function test_FR_ACC_06_without_a_plan_no_day_can_be_checked_in(): void
    {
        $this->expectException(PlanRequired::class);

        $this->checkInOn('2026-11-17');
    }

    public function test_FR_ACC_08_an_account_that_was_deleted_in_the_meantime_checks_nothing_in(): void
    {
        $this->expectException(AccountNotFound::class);

        $this->checkIn()->execute(TestData::userId(999), self::date('2026-11-17'));
    }

    public function test_NFR_03_a_check_in_is_one_transaction_that_locks_the_account_first(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');

        $this->checkInOn('2026-11-17');

        self::assertSame(1, $this->transactions->commits);
        self::assertSame('users', $this->transactions->locks[0] ?? null);
    }

    public function test_FR_LOG_04_a_day_past_its_48_hours_cannot_be_checked_in_even_before_the_cron_job_has_made_it_final(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');
        // The 15th ended at 23:00 UTC on the 15th. The cron job has not run since.
        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));

        try {
            $this->checkInOn('2026-11-15');
            self::fail('Expected the check-in to be refused: the 48 hours are over.');
        } catch (DayNotOpen $notOpen) {
            self::assertSame(Rejection::DayFinalized, $notOpen->reason);
            self::assertSame('The day is not open for a check-in: day_finalized.', $notOpen->getMessage());
        }

        self::assertTrue($this->checkInOn('2026-11-16')->day->checkedIn);

        // A second earlier the 15th was still open.
        $bob = $this->givenUser('bob@example.org');
        $this->givenOnboarded($bob, '2026-11-10', '2026-11-15');
        $this->clock->travelTo(TestData::time('2026-11-17 22:59:59'));

        self::assertTrue($this->checkIn()->execute($bob->id, self::date('2026-11-15'))->day->checkedIn);
    }

    public function test_a_plan_without_progress_is_damaged_data_and_stops_the_check_in(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $this->progressRecords->eraseOfUser($this->anna->id);

        try {
            $this->checkInOn('2026-11-17');
            self::fail('Expected the damaged data to stop the request.');
        } catch (LogicException $damaged) {
            self::assertSame('A plan exists without progress.', $damaged->getMessage());
        }

        self::assertSame([], $this->dayRecords->ofUser($this->anna->id));
    }

    private function checkInOn(string $date): CheckInResult
    {
        return $this->checkIn()->execute($this->anna->id, self::date($date));
    }
}
