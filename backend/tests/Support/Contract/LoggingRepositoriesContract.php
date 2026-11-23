<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Day\XpLedger;
use SmokeDetector\Application\Logging\SmokeLogs;
use SmokeDetector\Application\Plan\PlanHistory;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\ZoneHistory;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\DayEventType;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Tests\Support\TestData;

/**
 * What the repositories of step 4 do (HLD §4.3, §4.6): logs, days, progress, the XP ledger and the
 * histories of plan and time zone. The in-memory ones and the PDO ones both run these tests.
 */
trait LoggingRepositoriesContract
{
    abstract protected function smokeLogs(): SmokeLogs;

    abstract protected function dayRecords(): DayRecords;

    abstract protected function progressRecords(): ProgressRecords;

    abstract protected function xpLedger(): XpLedger;

    abstract protected function planHistory(): PlanHistory;

    abstract protected function zoneHistory(): ZoneHistory;

    /** Makes sure the accounts exist, where rows cannot exist without one. */
    abstract protected function givenUsers(UserId ...$ids): void;

    public function test_FR_LOG_02_a_saved_log_is_found_by_its_id_with_every_value(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $plain = TestData::smokeLog(10, 1, '2026-11-17', '2026-11-17 06:15:59', 1, ProductType::Cigarette, '2026-11-17 06:16:02');
        // Logged late in the evening in Berlin: the instant is the 17th in UTC, the local date the 18th.
        $edited = TestData::smokeLog(11, 2, '2026-11-18', '2026-11-17 23:30:00', 50, ProductType::HeatedTobacco, '2026-11-18 07:00:00', '2026-11-18 09:00:00');
        $deleted = TestData::smokeLog(12, 1, '2026-11-17', '2026-11-17 20:00:00', 3, ProductType::Shisha, '2026-11-17 20:00:05', '2026-11-17 21:00:00', '2026-11-17 21:00:00');

        self::assertTrue($this->smokeLogs()->save($plain));
        self::assertTrue($this->smokeLogs()->save($edited));
        self::assertTrue($this->smokeLogs()->save($deleted));

        self::assertEquals($plain, $this->smokeLogs()->find(TestData::uuid(10)));
        self::assertEquals($edited, $this->smokeLogs()->find(TestData::uuid(11)));
        self::assertEquals($deleted, $this->smokeLogs()->find(TestData::uuid(12)));
        self::assertNull($this->smokeLogs()->find(TestData::uuid(13)));
    }

    public function test_FR_LOG_02_saving_a_known_id_again_replaces_the_log_and_makes_no_second_one(): void
    {
        $this->givenUsers(TestData::userId(1));
        $this->smokeLogs()->save(TestData::smokeLog(10, 1, '2026-11-17', '2026-11-17 08:00:00', 1, ProductType::Cigarette, '2026-11-17 08:00:03'));

        $stored = $this->smokeLogs()->save(
            TestData::smokeLog(10, 1, '2026-11-16', '2026-11-16 21:00:00', 4, ProductType::Vape, '2026-11-17 09:30:00', '2026-11-17 09:30:00', '2026-11-17 09:30:00'),
        );

        self::assertTrue($stored);
        // Everything is replaced except when the log was first stored.
        self::assertEquals(
            TestData::smokeLog(10, 1, '2026-11-16', '2026-11-16 21:00:00', 4, ProductType::Vape, '2026-11-17 08:00:03', '2026-11-17 09:30:00', '2026-11-17 09:30:00'),
            $this->smokeLogs()->find(TestData::uuid(10)),
        );
    }

    public function test_FR_LOG_02_the_same_log_saved_twice_is_stored_once(): void
    {
        $this->givenUsers(TestData::userId(1));
        $log = TestData::smokeLog(10, 1, '2026-11-17', count: 2);

        self::assertTrue($this->smokeLogs()->save($log));
        self::assertTrue($this->smokeLogs()->save($log));

        self::assertEquals($log, $this->smokeLogs()->find(TestData::uuid(10)));
        self::assertSame(2, $this->smokeLogs()->sessionsOn(TestData::userId(1), TestData::date('2026-11-17')));
    }

    public function test_NFR_05_a_log_under_the_id_of_another_users_log_is_not_stored(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $owned = TestData::smokeLog(10, 1, '2026-11-17', count: 2);
        $this->smokeLogs()->save($owned);

        $stored = $this->smokeLogs()->save(TestData::smokeLog(10, 2, '2026-11-17', count: 9, deletedAt: '2026-11-17 13:00:00'));

        self::assertFalse($stored);
        self::assertEquals($owned, $this->smokeLogs()->find(TestData::uuid(10)));
        self::assertSame(2, $this->smokeLogs()->sessionsOn(TestData::userId(1), TestData::date('2026-11-17')));
        self::assertSame(0, $this->smokeLogs()->sessionsOn(TestData::userId(2), TestData::date('2026-11-17')));
    }

    public function test_FR_LOG_03_the_sessions_of_a_day_are_the_counts_of_its_logs_that_are_not_deleted(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->smokeLogs()->save(TestData::smokeLog(10, 1, '2026-11-17', count: 1));
        $this->smokeLogs()->save(TestData::smokeLog(11, 1, '2026-11-17', count: 3));
        $this->smokeLogs()->save(TestData::smokeLog(12, 1, '2026-11-17', count: 5, deletedAt: '2026-11-17 13:00:00'));
        $this->smokeLogs()->save(TestData::smokeLog(13, 1, '2026-11-18', count: 2));
        $this->smokeLogs()->save(TestData::smokeLog(14, 2, '2026-11-17', count: 7));

        self::assertSame(4, $this->smokeLogs()->sessionsOn(TestData::userId(1), TestData::date('2026-11-17')));
        self::assertSame(2, $this->smokeLogs()->sessionsOn(TestData::userId(1), TestData::date('2026-11-18')));
        self::assertSame(7, $this->smokeLogs()->sessionsOn(TestData::userId(2), TestData::date('2026-11-17')));
        self::assertSame(0, $this->smokeLogs()->sessionsOn(TestData::userId(1), TestData::date('2026-11-19')));
        self::assertSame(0, $this->smokeLogs()->sessionsOn(TestData::userId(3), TestData::date('2026-11-17')));
    }

    public function test_FR_LOG_03_a_log_that_is_moved_or_deleted_leaves_the_sessions_of_its_old_day(): void
    {
        $this->givenUsers(TestData::userId(1));
        $this->smokeLogs()->save(TestData::smokeLog(10, 1, '2026-11-17', count: 3));
        $this->smokeLogs()->save(TestData::smokeLog(11, 1, '2026-11-17', count: 2));

        $this->smokeLogs()->save(TestData::smokeLog(10, 1, '2026-11-16', count: 3));
        $this->smokeLogs()->save(TestData::smokeLog(11, 1, '2026-11-17', count: 2, deletedAt: '2026-11-17 14:00:00'));

        self::assertSame(3, $this->smokeLogs()->sessionsOn(TestData::userId(1), TestData::date('2026-11-16')));
        self::assertSame(0, $this->smokeLogs()->sessionsOn(TestData::userId(1), TestData::date('2026-11-17')));
    }

    public function test_FR_GAME_09_the_last_session_is_the_latest_log_that_is_not_deleted_whenever_it_was_stored(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        // Stored in another order than the sessions happened. The latest of all is deleted.
        $this->smokeLogs()->save(TestData::smokeLog(10, 1, '2026-11-16', '2026-11-16 21:40:07'));
        $this->smokeLogs()->save(TestData::smokeLog(11, 1, '2026-11-15', '2026-11-15 08:00:00'));
        $this->smokeLogs()->save(TestData::smokeLog(12, 1, '2026-11-17', '2026-11-17 07:00:00', deletedAt: '2026-11-17 09:00:00'));
        $this->smokeLogs()->save(TestData::smokeLog(13, 2, '2026-11-17', '2026-11-17 11:00:00'));

        self::assertEquals(TestData::time('2026-11-16 21:40:07'), $this->smokeLogs()->lastSessionAt(TestData::userId(1)));
        self::assertEquals(TestData::time('2026-11-17 11:00:00'), $this->smokeLogs()->lastSessionAt(TestData::userId(2)));
        self::assertNull($this->smokeLogs()->lastSessionAt(TestData::userId(3)));

        // A log that is deleted later is not the last session any more, and one that is brought back is.
        $this->smokeLogs()->save(TestData::smokeLog(10, 1, '2026-11-16', '2026-11-16 21:40:07', deletedAt: '2026-11-17 12:00:00'));
        self::assertEquals(TestData::time('2026-11-15 08:00:00'), $this->smokeLogs()->lastSessionAt(TestData::userId(1)));
        $this->smokeLogs()->save(TestData::smokeLog(11, 1, '2026-11-15', '2026-11-15 08:00:00', deletedAt: '2026-11-17 12:01:00'));
        self::assertNull($this->smokeLogs()->lastSessionAt(TestData::userId(1)));
    }

    public function test_FR_GAME_09_the_last_session_up_to_a_day_leaves_out_the_days_after_it(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->smokeLogs()->save(TestData::smokeLog(10, 1, '2026-11-14', '2026-11-14 10:00:00'));
        // A session after midnight UTC that belongs to the 15th of the user, and one that is deleted.
        $this->smokeLogs()->save(TestData::smokeLog(11, 1, '2026-11-15', '2026-11-15 22:30:00'));
        $this->smokeLogs()->save(TestData::smokeLog(12, 1, '2026-11-15', '2026-11-15 22:45:00', deletedAt: '2026-11-16 08:00:00'));
        $this->smokeLogs()->save(TestData::smokeLog(13, 1, '2026-11-17', '2026-11-17 07:00:00'));
        $this->smokeLogs()->save(TestData::smokeLog(14, 2, '2026-11-14', '2026-11-14 23:59:59'));

        self::assertNull($this->smokeLogs()->lastSessionUntil(TestData::userId(1), TestData::date('2026-11-13')));
        self::assertEquals(TestData::time('2026-11-14 10:00:00'), $this->smokeLogs()->lastSessionUntil(TestData::userId(1), TestData::date('2026-11-14')));
        self::assertEquals(TestData::time('2026-11-15 22:30:00'), $this->smokeLogs()->lastSessionUntil(TestData::userId(1), TestData::date('2026-11-15')));
        // A day without a session has the session before it.
        self::assertEquals(TestData::time('2026-11-15 22:30:00'), $this->smokeLogs()->lastSessionUntil(TestData::userId(1), TestData::date('2026-11-16')));
        self::assertEquals(TestData::time('2026-11-17 07:00:00'), $this->smokeLogs()->lastSessionUntil(TestData::userId(1), TestData::date('2026-11-30')));
        self::assertNull($this->smokeLogs()->lastSessionUntil(TestData::userId(3), TestData::date('2026-11-30')));
    }

    public function test_FR_GAME_09_the_last_session_up_to_a_day_is_the_latest_by_time_also_when_it_was_logged_for_an_earlier_day(): void
    {
        $this->givenUsers(TestData::userId(1));
        // After a move west a later moment can belong to an earlier day of the user.
        $this->smokeLogs()->save(TestData::smokeLog(10, 1, '2026-11-15', '2026-11-15 08:00:00'));
        $this->smokeLogs()->save(TestData::smokeLog(11, 1, '2026-11-14', '2026-11-15 09:00:00'));

        self::assertEquals(TestData::time('2026-11-15 09:00:00'), $this->smokeLogs()->lastSessionUntil(TestData::userId(1), TestData::date('2026-11-15')));
        self::assertEquals(TestData::time('2026-11-15 09:00:00'), $this->smokeLogs()->lastSessionUntil(TestData::userId(1), TestData::date('2026-11-14')));
    }

    public function test_FR_GAME_09_the_products_of_a_user_are_those_of_the_logs_that_are_not_deleted_each_once(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $this->smokeLogs()->save(TestData::smokeLog(10, 1, productType: ProductType::Vape));
        $this->smokeLogs()->save(TestData::smokeLog(11, 1, productType: ProductType::Cigarette));
        $this->smokeLogs()->save(TestData::smokeLog(12, 1, productType: ProductType::Vape));
        $this->smokeLogs()->save(TestData::smokeLog(13, 1, productType: ProductType::Shisha, deletedAt: '2026-11-17 13:00:00'));
        $this->smokeLogs()->save(TestData::smokeLog(14, 2, productType: ProductType::Vape));
        $this->smokeLogs()->save(TestData::smokeLog(15, 2, productType: ProductType::Other, deletedAt: '2026-11-17 13:00:00'));

        // In the order of the product types, not of the logs.
        self::assertSame([ProductType::Cigarette, ProductType::Vape], $this->smokeLogs()->productsOf(TestData::userId(1)));
        self::assertSame([ProductType::Vape], $this->smokeLogs()->productsOf(TestData::userId(2)));
        self::assertSame([], $this->smokeLogs()->productsOf(TestData::userId(3)));
    }

    public function test_FR_LOG_04_a_saved_day_is_found_with_every_value(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $open = TestData::day(1, '2026-11-17', 4);
        $checkedIn = TestData::day(1, '2026-11-18', 300, '2026-11-18 21:45:10');
        $final = TestData::day(2, '2026-11-17', 0, '2026-11-17 22:00:00', TestData::dayResult(
            7,
            120,
            [new DayEvent(DayEventType::CheckedIn), new DayEvent(DayEventType::UnderLimitDay, 3), new DayEvent(DayEventType::SmokeFreeDay), new DayEvent(DayEventType::LevelUp, 0)],
            false,
            '2026-11-19 23:00:41',
            'v2.beta',
        ));
        $missed = TestData::day(2, '2026-11-18', 12, null, TestData::dayResult(999, 0, [new DayEvent(DayEventType::FreezeUsed, 1)], true));

        foreach ([$open, $checkedIn, $final, $missed] as $day) {
            $this->dayRecords()->save($day);
        }

        self::assertEquals($open, $this->dayRecords()->find(TestData::userId(1), TestData::date('2026-11-17')));
        self::assertEquals($checkedIn, $this->dayRecords()->find(TestData::userId(1), TestData::date('2026-11-18')));
        self::assertEquals($final, $this->dayRecords()->find(TestData::userId(2), TestData::date('2026-11-17')));
        self::assertEquals($missed, $this->dayRecords()->find(TestData::userId(2), TestData::date('2026-11-18')));
        self::assertNull($this->dayRecords()->find(TestData::userId(1), TestData::date('2026-11-19')));
        self::assertNull($this->dayRecords()->find(TestData::userId(3), TestData::date('2026-11-17')));
    }

    public function test_FR_LOG_04_a_day_says_whether_it_is_checked_in_and_whether_it_is_final(): void
    {
        $open = TestData::day(1, '2026-11-17', 4);
        $checkedIn = TestData::day(1, '2026-11-18', 4, '2026-11-18 21:45:10');
        $missed = TestData::day(1, '2026-11-16', 4, null, TestData::dayResult());

        self::assertSame([false, false], [$open->isCheckedIn(), $open->isFinal()]);
        self::assertSame([true, false], [$checkedIn->isCheckedIn(), $checkedIn->isFinal()]);
        self::assertSame([false, true], [$missed->isCheckedIn(), $missed->isFinal()]);
    }

    public function test_saving_a_day_again_replaces_the_day_of_that_user_and_date_only(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->dayRecords()->save(TestData::day(1, '2026-11-17', 4, '2026-11-17 21:00:00'));
        $this->dayRecords()->save(TestData::day(1, '2026-11-18', 1));
        $this->dayRecords()->save(TestData::day(2, '2026-11-17', 9));
        $final = TestData::day(1, '2026-11-17', 5, '2026-11-17 21:00:00', TestData::dayResult(9, 30, [new DayEvent(DayEventType::CheckedIn)]));

        $this->dayRecords()->save($final);
        // And back to a day without anything: nothing of the old row is left over.
        $this->dayRecords()->save(TestData::day(1, '2026-11-18', 0));

        self::assertEquals($final, $this->dayRecords()->find(TestData::userId(1), TestData::date('2026-11-17')));
        self::assertEquals(TestData::day(1, '2026-11-18', 0), $this->dayRecords()->find(TestData::userId(1), TestData::date('2026-11-18')));
        self::assertEquals(TestData::day(2, '2026-11-17', 9), $this->dayRecords()->find(TestData::userId(2), TestData::date('2026-11-17')));
    }

    public function test_FR_GAME_09_the_last_day_without_a_check_in_up_to_a_day(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        // Stored in another order than the days have. The 15th and the 18th have no check-in.
        $this->dayRecords()->save(TestData::day(1, '2026-11-18', 4));
        $this->dayRecords()->save(TestData::day(1, '2026-11-14', 0, '2026-11-14 21:00:00'));
        $this->dayRecords()->save(TestData::day(1, '2026-11-15', 0));
        $this->dayRecords()->save(TestData::day(1, '2026-11-16', 2, '2026-11-17 07:00:00'));
        $this->dayRecords()->save(TestData::day(1, '2026-11-17', 0, '2026-11-17 21:00:00'));
        $this->dayRecords()->save(TestData::day(2, '2026-11-16', 0));

        $last = fn (int $user, string $day): ?string => $this->dayRecords()->lastMissedUntil(TestData::userId($user), TestData::date($day))?->value;

        self::assertNull($last(1, '2026-11-14'));
        self::assertSame('2026-11-15', $last(1, '2026-11-15'));
        self::assertSame('2026-11-15', $last(1, '2026-11-17'));
        self::assertSame('2026-11-18', $last(1, '2026-11-18'));
        self::assertSame('2026-11-18', $last(1, '2026-12-31'));
        // The days of another user, and a user without a day.
        self::assertSame('2026-11-16', $last(2, '2026-11-17'));
        self::assertNull($last(3, '2026-11-17'));
        // A day that is checked in later is not missed any more.
        $this->dayRecords()->save(TestData::day(1, '2026-11-18', 4, '2026-11-19 08:00:00'));
        self::assertSame('2026-11-15', $last(1, '2026-11-18'));
    }

    public function test_the_days_between_two_dates_come_oldest_first_and_include_both_ends(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        foreach (['2026-11-19', '2026-11-16', '2026-11-18', '2026-11-20', '2026-12-01'] as $date) {
            $this->dayRecords()->save(TestData::day(1, $date, 2));
        }
        $this->dayRecords()->save(TestData::day(2, '2026-11-17', 9));

        $dates = static function (array $days): array {
            $dates = [];
            foreach ($days as $day) {
                self::assertInstanceOf(DayRecord::class, $day);
                $dates[] = $day->date->value;
            }

            return $dates;
        };

        self::assertSame(
            ['2026-11-16', '2026-11-18', '2026-11-19'],
            $dates($this->dayRecords()->between(TestData::userId(1), TestData::date('2026-11-16'), TestData::date('2026-11-19'))),
        );
        self::assertSame(['2026-11-18'], $dates($this->dayRecords()->between(TestData::userId(1), TestData::date('2026-11-18'), TestData::date('2026-11-18'))));
        self::assertSame([], $this->dayRecords()->between(TestData::userId(1), TestData::date('2026-11-21'), TestData::date('2026-11-30')));
        self::assertSame([], $this->dayRecords()->between(TestData::userId(1), TestData::date('2026-11-19'), TestData::date('2026-11-18')));
        self::assertEquals(
            [TestData::day(2, '2026-11-17', 9)],
            $this->dayRecords()->between(TestData::userId(2), TestData::date('2026-01-01'), TestData::date('2026-12-31')),
        );
    }

    public function test_FR_GAME_02_saved_progress_is_found_with_every_value(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $fresh = TestData::progress(1);
        $advanced = TestData::progress(2, [12, 40, 3, 21, 2], 123456, 70, '2027-02-28', '2027-03-02 23:00:00', '2027-03-07 23:00:00');

        $this->progressRecords()->save($fresh);
        $this->progressRecords()->save($advanced);

        self::assertEquals($fresh, $this->progressRecords()->find(TestData::userId(1)));
        self::assertEquals($advanced, $this->progressRecords()->find(TestData::userId(2)));
        self::assertNull($this->progressRecords()->find(TestData::userId(3)));
    }

    public function test_saving_progress_again_replaces_the_progress_of_that_user_only(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->progressRecords()->save(TestData::progress(1));
        $this->progressRecords()->save(TestData::progress(2));
        $next = TestData::progress(1, [1, 1, 1, 1, 0], 30, 0, '2026-11-18', '2026-11-20 23:00:00');

        $this->progressRecords()->save($next);

        self::assertEquals($next, $this->progressRecords()->find(TestData::userId(1)));
        self::assertEquals(TestData::progress(2), $this->progressRecords()->find(TestData::userId(2)));
    }

    public function test_the_users_whose_next_day_or_next_baseline_is_due_come_longest_waiting_first(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3), TestData::userId(4), TestData::userId(5));
        // Due: a day since 11:00 (1), a baseline since 10:00 (2), a day exactly now (3).
        $this->progressRecords()->save(TestData::progress(1, finalizeDueAt: '2026-11-20 11:00:00', baselineDueAt: '2026-11-29 23:00:00'));
        $this->progressRecords()->save(TestData::progress(2, finalizeDueAt: '2026-11-21 23:00:00', baselineDueAt: '2026-11-20 10:00:00'));
        $this->progressRecords()->save(TestData::progress(3, finalizeDueAt: '2026-11-20 12:00:00', baselineDueAt: '2026-11-20 12:00:01'));
        // Not due: a second too early (4), far ahead (5).
        $this->progressRecords()->save(TestData::progress(4, finalizeDueAt: '2026-11-20 12:00:01', baselineDueAt: '2026-11-23 23:00:00'));
        $this->progressRecords()->save(TestData::progress(5, finalizeDueAt: '2027-01-01 00:00:00', baselineDueAt: '2027-01-01 00:00:00'));
        $now = TestData::time('2026-11-20 12:00:00');

        self::assertEquals([TestData::userId(2), TestData::userId(1), TestData::userId(3)], $this->progressRecords()->due($now, 10));
        self::assertEquals([TestData::userId(2), TestData::userId(1)], $this->progressRecords()->due($now, 2));
        self::assertSame([], $this->progressRecords()->due(TestData::time('2026-11-20 09:59:59'), 10));
    }

    public function test_FR_COMP_07_the_xp_of_a_time_span_is_the_sum_of_what_was_written_in_it(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        // Written at: the first instant of the span, inside it, its last second, and the instant it ends.
        $this->xpLedger()->append(TestData::xpEvent(20, 1, '2026-11-14', XpSource::CheckIn, 10, null, '2026-11-15 23:00:00'));
        $this->xpLedger()->append(TestData::xpEvent(21, 1, '2026-11-15', XpSource::StreakMilestone, 50, 7, '2026-11-17 23:00:30'));
        $this->xpLedger()->append(TestData::xpEvent(22, 1, '2026-11-22', XpSource::Duel, 100, null, '2026-11-22 22:59:59'));
        $this->xpLedger()->append(TestData::xpEvent(23, 1, '2026-11-22', XpSource::League, 150, 1, '2026-11-22 23:00:00'));
        // Before the span, and somebody else's.
        $this->xpLedger()->append(TestData::xpEvent(24, 1, '2026-11-13', XpSource::UnderLimit, 20, null, '2026-11-15 22:59:59'));
        $this->xpLedger()->append(TestData::xpEvent(25, 2, '2026-11-16', XpSource::SmokeFree, 40, null, '2026-11-18 23:00:00'));
        $from = TestData::time('2026-11-15 23:00:00');
        $until = TestData::time('2026-11-22 23:00:00');

        // By the time an entry was written, not by the day it belongs to.
        self::assertSame(160, $this->xpLedger()->earnedBetween(TestData::userId(1), $from, $until));
        self::assertSame(40, $this->xpLedger()->earnedBetween(TestData::userId(2), $from, $until));
        self::assertSame(0, $this->xpLedger()->earnedBetween(TestData::userId(3), $from, $until));
        self::assertSame(0, $this->xpLedger()->earnedBetween(TestData::userId(1), $until->modify('+1 second'), $until->modify('+1 week')));
    }

    public function test_FR_GAME_01_xp_events_are_kept_and_read_by_day_and_then_in_the_order_of_their_ids(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $milestone = TestData::xpEvent(12, 1, '2026-11-18', XpSource::StreakMilestone, 1500, 365, '2026-11-20 23:00:45');
        $checkIn = TestData::xpEvent(11, 1, '2026-11-18', XpSource::CheckIn, 10);
        $earlier = TestData::xpEvent(13, 1, '2026-11-17', XpSource::SmokeFree, 40);
        $ofBob = TestData::xpEvent(10, 2, '2026-11-17', XpSource::UnderLimit, 20);

        foreach ([$milestone, $checkIn, $earlier, $ofBob] as $event) {
            $this->xpLedger()->append($event);
        }

        self::assertEquals([$earlier, $checkIn, $milestone], $this->xpLedger()->ofUser(TestData::userId(1)));
        self::assertEquals([$ofBob], $this->xpLedger()->ofUser(TestData::userId(2)));
        self::assertSame([], $this->xpLedger()->ofUser(TestData::userId(3)));
    }

    public function test_FR_I18N_02_the_history_of_the_plan_is_read_oldest_first_with_every_value(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $changed = TestData::planTerms(1, '2026-11-25', Goal::quit(), TaperSpeed::Fast, 'v2.beta');
        $first = TestData::planTerms(1, '2026-11-17', Goal::reduceTo(999), TaperSpeed::Gentle);
        $ofBob = TestData::planTerms(2, '2026-11-20');

        foreach ([$changed, $first, $ofBob] as $terms) {
            $this->planHistory()->record($terms);
        }

        self::assertEquals([$first, $changed], $this->planHistory()->ofUser(TestData::userId(1)));
        self::assertEquals([$ofBob], $this->planHistory()->ofUser(TestData::userId(2)));
        self::assertSame([], $this->planHistory()->ofUser(TestData::userId(3)));
    }

    public function test_FR_I18N_02_a_second_plan_change_for_the_same_day_replaces_the_first(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->planHistory()->record(TestData::planTerms(1, '2026-11-17'));
        $this->planHistory()->record(TestData::planTerms(1, '2026-11-18', Goal::reduceTo(3), TaperSpeed::Gentle));
        $this->planHistory()->record(TestData::planTerms(2, '2026-11-18'));

        $this->planHistory()->record(TestData::planTerms(1, '2026-11-18', Goal::quit(), TaperSpeed::Fast));

        self::assertEquals(
            [TestData::planTerms(1, '2026-11-17'), TestData::planTerms(1, '2026-11-18', Goal::quit(), TaperSpeed::Fast)],
            $this->planHistory()->ofUser(TestData::userId(1)),
        );
        self::assertEquals([TestData::planTerms(2, '2026-11-18')], $this->planHistory()->ofUser(TestData::userId(2)));
    }

    public function test_FR_I18N_02_the_history_of_the_time_zone_is_read_oldest_first(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->zoneHistory()->record(TestData::userId(1), TestData::zoneChange('2026-11-25', 'Asia/Calcutta'));
        $this->zoneHistory()->record(TestData::userId(1), TestData::zoneChange('2026-11-17', 'Europe/Berlin'));
        $this->zoneHistory()->record(TestData::userId(2), TestData::zoneChange('2026-11-20', 'Asia/Tokyo'));

        self::assertEquals(
            // An older name is kept under its current one, as everywhere (TimeZone).
            [TestData::zoneChange('2026-11-17', 'Europe/Berlin'), TestData::zoneChange('2026-11-25', 'Asia/Kolkata')],
            $this->zoneHistory()->ofUser(TestData::userId(1)),
        );
        self::assertEquals([TestData::zoneChange('2026-11-20', 'Asia/Tokyo')], $this->zoneHistory()->ofUser(TestData::userId(2)));
        self::assertSame([], $this->zoneHistory()->ofUser(TestData::userId(3)));
    }

    public function test_FR_I18N_02_zone_changes_that_are_not_in_force_yet_can_be_taken_back(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->zoneHistory()->record(TestData::userId(1), TestData::zoneChange('2026-11-17', 'Europe/Berlin'));
        $this->zoneHistory()->record(TestData::userId(1), TestData::zoneChange('2026-11-18', 'Asia/Tokyo'));
        $this->zoneHistory()->record(TestData::userId(1), TestData::zoneChange('2026-11-19', 'America/New_York'));
        $this->zoneHistory()->record(TestData::userId(2), TestData::zoneChange('2026-11-18', 'Asia/Tokyo'));

        // Everything that counts from a day after the 17th. The entry of the 17th itself stays.
        $this->zoneHistory()->dropAfter(TestData::userId(1), TestData::date('2026-11-17'));

        self::assertEquals([TestData::zoneChange('2026-11-17', 'Europe/Berlin')], $this->zoneHistory()->ofUser(TestData::userId(1)));
        self::assertEquals([TestData::zoneChange('2026-11-18', 'Asia/Tokyo')], $this->zoneHistory()->ofUser(TestData::userId(2)));

        // Nothing later is left, and a user without a history has nothing to take back.
        $this->zoneHistory()->dropAfter(TestData::userId(1), TestData::date('2026-11-17'));
        $this->zoneHistory()->dropAfter(TestData::userId(1), TestData::date('2026-11-16'));
        $this->zoneHistory()->dropAfter(TestData::userId(3), TestData::date('2026-11-17'));

        self::assertSame([], $this->zoneHistory()->ofUser(TestData::userId(1)));
        self::assertSame([], $this->zoneHistory()->ofUser(TestData::userId(3)));
    }

    public function test_FR_I18N_02_a_second_zone_change_for_the_same_day_replaces_the_first(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->zoneHistory()->record(TestData::userId(1), TestData::zoneChange('2026-11-17', 'Europe/Berlin'));
        $this->zoneHistory()->record(TestData::userId(1), TestData::zoneChange('2026-11-18', 'Asia/Tokyo'));
        $this->zoneHistory()->record(TestData::userId(2), TestData::zoneChange('2026-11-18', 'Asia/Tokyo'));

        $this->zoneHistory()->record(TestData::userId(1), TestData::zoneChange('2026-11-18', 'America/New_York'));

        self::assertEquals(
            [TestData::zoneChange('2026-11-17', 'Europe/Berlin'), TestData::zoneChange('2026-11-18', 'America/New_York')],
            $this->zoneHistory()->ofUser(TestData::userId(1)),
        );
        self::assertEquals([TestData::zoneChange('2026-11-18', 'Asia/Tokyo')], $this->zoneHistory()->ofUser(TestData::userId(2)));
    }
}
