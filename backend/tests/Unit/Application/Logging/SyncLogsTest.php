<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Logging;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Application\Logging\LogItem;
use SmokeDetector\Application\Logging\Rejection;
use SmokeDetector\Application\Logging\SmokeLog;
use SmokeDetector\Application\Logging\SyncLogs;
use SmokeDetector\Application\Logging\SyncResult;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\Plan\PlanTerms;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * POST /logs/sync (HLD §4.6, FR-LOG-02, -03, -05, -07, NFR-03): a batch of logs and deletions from
 * the outbox of an app. Every item gets its own answer, and the days it touched come back.
 *
 * Anna did the onboarding on the 10th. The days up to the 14th are final, so the 15th, the 16th and
 * today, the 17th, are open.
 */
final class SyncLogsTest extends LoggingUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');
    }

    public function test_FR_LOG_02_a_log_is_stored_and_its_day_comes_back_with_count_limit_and_what_is_left(): void
    {
        $result = $this->sync(self::entry(1, '2026-11-17 11:59:00', 2, ProductType::Rolled));

        self::assertSame([[TestData::uuid(1), null]], self::answers($result));
        self::assertSame([['2026-11-17', 2, 9, 7, false, false]], self::views($result->days));
        self::assertEquals(
            [new SmokeLog(TestData::uuid(1), $this->anna->id, TestData::time('2026-11-17 11:59:00'), self::date('2026-11-17'), ProductType::Rolled, 2, $this->now(), $this->now())],
            $this->smokeLogs->all(),
        );
        self::assertEquals([new DayRecord($this->anna->id, self::date('2026-11-17'), 2)], $this->dayRecords->ofUser($this->anna->id));
    }

    public function test_FR_I18N_02_the_server_works_out_the_local_day_from_the_instant(): void
    {
        // Midnight in Vienna is 23:00 UTC.
        $result = $this->sync(self::entry(1, '2026-11-16 22:59:59'), self::entry(2, '2026-11-16 23:00:00'), self::entry(3, '2026-11-15 23:00:00'));

        self::assertSame(
            [TestData::uuid(1) => '2026-11-16', TestData::uuid(2) => '2026-11-17', TestData::uuid(3) => '2026-11-16'],
            $this->storedDates(),
        );
        self::assertSame([['2026-11-16', 2, 9, 7, false, false], ['2026-11-17', 1, 9, 8, false, false]], self::views($result->days));
    }

    public function test_FR_I18N_02_the_instant_may_come_in_any_zone(): void
    {
        // 07:30 on the 17th in Tokyo is 23:30 on the 16th in Vienna.
        $inTokyo = new DateTimeImmutable('2026-11-17 07:30:00', new DateTimeZone('Asia/Tokyo'));

        $this->sync(LogItem::entry(TestData::uuid(1), $inTokyo, ProductType::Cigarette, 1));

        $stored = $this->smokeLogs->find(TestData::uuid(1));
        self::assertNotNull($stored);
        self::assertSame('2026-11-16', $stored->localDate->value);
        self::assertSame('2026-11-16 22:30:00 UTC', $stored->smokedAt->format('Y-m-d H:i:s T'));
    }

    public function test_FR_I18N_02_a_time_zone_that_counts_from_tomorrow_does_not_move_todays_logs(): void
    {
        // Anna changed her zone to Tokyo today. It counts from the 18th.
        $this->givenZoneChange($this->anna, '2026-11-18', 'Asia/Tokyo');

        // 22:30 UTC on the 16th is the 17th in Tokyo, and still the 16th in Vienna.
        $this->sync(self::entry(1, '2026-11-16 22:30:00'));

        self::assertSame([TestData::uuid(1) => '2026-11-16'], $this->storedDates());
    }

    public function test_FR_LOG_02_the_same_item_sent_again_changes_nothing_and_is_accepted_again(): void
    {
        $first = $this->sync(self::entry(1, '2026-11-17 08:00:00', 2));
        $this->clock->advanceSeconds(60);

        $second = $this->sync(self::entry(1, '2026-11-17 08:00:00', 2));

        self::assertSame([[TestData::uuid(1), null]], self::answers($second));
        // The answer of the first try may have been lost on the way: the day comes back again.
        self::assertSame(self::views($first->days), self::views($second->days));
        self::assertSame([['2026-11-17', 2, 9, 7, false, false]], self::views($second->days));
        $stored = $this->smokeLogs->all();
        self::assertCount(1, $stored);
        self::assertEquals(TestData::time('2026-11-17 12:00:00'), $stored[0]->updatedAt);
    }

    public function test_FR_LOG_02_the_same_item_twice_in_one_batch_is_one_log(): void
    {
        $result = $this->sync(self::entry(1, '2026-11-17 08:00:00', 2), self::entry(1, '2026-11-17 08:00:00', 2));

        self::assertSame([[TestData::uuid(1), null], [TestData::uuid(1), null]], self::answers($result));
        self::assertSame([['2026-11-17', 2, 9, 7, false, false]], self::views($result->days));
        self::assertCount(1, $this->smokeLogs->all());
    }

    public function test_FR_LOG_02_fractions_of_a_second_are_dropped_and_do_not_make_a_resent_item_look_changed(): void
    {
        $item = LogItem::entry(TestData::uuid(1), new DateTimeImmutable('2026-11-17 08:00:00.789 UTC'), ProductType::Cigarette, 1);
        $this->sync($item);
        $this->clock->advanceSeconds(60);

        $this->sync($item);

        $stored = $this->smokeLogs->find(TestData::uuid(1));
        self::assertNotNull($stored);
        self::assertSame('2026-11-17 08:00:00.000000', $stored->smokedAt->format('Y-m-d H:i:s.u'));
        self::assertEquals(TestData::time('2026-11-17 12:00:00'), $stored->updatedAt);
    }

    public function test_FR_LOG_03_a_known_id_with_other_values_corrects_the_log(): void
    {
        $this->sync(self::entry(1, '2026-11-17 08:00:00', 1), self::entry(2, '2026-11-17 09:00:00', 4));
        $this->clock->advanceSeconds(60);

        $result = $this->sync(self::entry(1, '2026-11-17 08:05:00', 3, ProductType::Vape));

        self::assertSame([[TestData::uuid(1), null]], self::answers($result));
        self::assertSame([['2026-11-17', 7, 9, 2, false, false]], self::views($result->days));
        self::assertEquals(
            new SmokeLog(
                TestData::uuid(1), $this->anna->id, TestData::time('2026-11-17 08:05:00'), self::date('2026-11-17'), ProductType::Vape, 3,
                TestData::time('2026-11-17 12:00:00'), TestData::time('2026-11-17 12:01:00'),
            ),
            $this->smokeLogs->find(TestData::uuid(1)),
        );
        self::assertEquals([new DayRecord($this->anna->id, self::date('2026-11-17'), 7)], $this->dayRecords->ofUser($this->anna->id));
    }

    public function test_FR_LOG_03_a_change_of_the_product_alone_is_a_correction_too(): void
    {
        $this->sync(self::entry(1, '2026-11-17 08:00:00', 1, ProductType::Cigarette));
        $this->clock->advanceSeconds(60);

        $this->sync(self::entry(1, '2026-11-17 08:00:00', 1, ProductType::Cigar));

        $stored = $this->smokeLogs->find(TestData::uuid(1));
        self::assertNotNull($stored);
        self::assertSame(ProductType::Cigar, $stored->productType);
        self::assertEquals(TestData::time('2026-11-17 12:01:00'), $stored->updatedAt);
    }

    public function test_FR_LOG_03_a_log_that_is_back_dated_to_another_open_day_leaves_its_old_day(): void
    {
        $this->sync(self::entry(1, '2026-11-17 08:00:00', 3), self::entry(2, '2026-11-17 09:00:00', 1));

        $result = $this->sync(self::entry(1, '2026-11-16 20:00:00', 3));

        // Both days come back, the older one first.
        self::assertSame([['2026-11-16', 3, 9, 6, false, false], ['2026-11-17', 1, 9, 8, false, false]], self::views($result->days));
        self::assertSame([TestData::uuid(1) => '2026-11-16', TestData::uuid(2) => '2026-11-17'], $this->storedDates());
        self::assertEquals(
            [new DayRecord($this->anna->id, self::date('2026-11-16'), 3), new DayRecord($this->anna->id, self::date('2026-11-17'), 1)],
            $this->dayRecords->ofUser($this->anna->id),
        );
    }

    public function test_FR_LOG_03_a_deleted_log_stays_stored_and_no_longer_counts(): void
    {
        $this->sync(self::entry(1, '2026-11-17 08:00:00', 5), self::entry(2, '2026-11-17 09:00:00', 4));
        $this->clock->advanceSeconds(60);

        $result = $this->sync(LogItem::deletion(TestData::uuid(1)));

        self::assertSame([[TestData::uuid(1), null]], self::answers($result));
        self::assertSame([['2026-11-17', 4, 9, 5, false, false]], self::views($result->days));
        self::assertEquals(
            new SmokeLog(
                TestData::uuid(1), $this->anna->id, TestData::time('2026-11-17 08:00:00'), self::date('2026-11-17'), ProductType::Cigarette, 5,
                TestData::time('2026-11-17 12:00:00'), TestData::time('2026-11-17 12:01:00'), TestData::time('2026-11-17 12:01:00'),
            ),
            $this->smokeLogs->find(TestData::uuid(1)),
        );
    }

    public function test_FR_LOG_02_a_deletion_sent_again_changes_nothing_and_is_accepted_again(): void
    {
        $this->sync(self::entry(1, '2026-11-17 08:00:00', 5));
        $this->clock->advanceSeconds(60);
        $this->sync(LogItem::deletion(TestData::uuid(1)));
        $this->clock->advanceSeconds(60);

        $result = $this->sync(LogItem::deletion(TestData::uuid(1)));

        self::assertSame([[TestData::uuid(1), null]], self::answers($result));
        self::assertSame([['2026-11-17', 0, 9, 9, false, false]], self::views($result->days));
        $stored = $this->smokeLogs->find(TestData::uuid(1));
        self::assertNotNull($stored);
        self::assertEquals(TestData::time('2026-11-17 12:01:00'), $stored->deletedAt);
        self::assertEquals(TestData::time('2026-11-17 12:01:00'), $stored->updatedAt);
    }

    public function test_FR_LOG_02_the_deletion_of_a_log_the_server_never_saw_is_accepted_and_stores_nothing(): void
    {
        $result = $this->sync(LogItem::deletion(TestData::uuid(1)));

        self::assertSame([[TestData::uuid(1), null]], self::answers($result));
        self::assertSame([], $result->days);
        self::assertSame([], $this->smokeLogs->all());
        self::assertSame([], $this->dayRecords->ofUser($this->anna->id));
    }

    public function test_FR_LOG_03_a_deleted_log_stays_deleted_when_an_old_item_for_it_arrives(): void
    {
        $this->sync(self::entry(1, '2026-11-17 08:00:00', 5), LogItem::deletion(TestData::uuid(1)));
        $deleted = $this->smokeLogs->find(TestData::uuid(1));
        $this->clock->advanceSeconds(60);

        $result = $this->sync(self::entry(1, '2026-11-16 08:00:00', 2));

        // Accepted, so that the app clears its outbox. The day of the log shows that it does not count.
        self::assertSame([[TestData::uuid(1), null]], self::answers($result));
        self::assertSame([['2026-11-17', 0, 9, 9, false, false]], self::views($result->days));
        self::assertEquals($deleted, $this->smokeLogs->find(TestData::uuid(1)));
    }

    public function test_FR_LOG_02_create_and_delete_in_one_batch_are_applied_in_order(): void
    {
        $result = $this->sync(self::entry(1, '2026-11-17 08:00:00', 5), self::entry(2, '2026-11-17 09:00:00', 1), LogItem::deletion(TestData::uuid(1)));

        self::assertSame([[TestData::uuid(1), null], [TestData::uuid(2), null], [TestData::uuid(1), null]], self::answers($result));
        self::assertSame([['2026-11-17', 1, 9, 8, false, false]], self::views($result->days));
    }

    public function test_NFR_05_the_id_of_another_users_log_is_refused_and_that_log_is_not_touched(): void
    {
        $bob = $this->givenUser('bob@example.org');
        $this->givenOnboarded($bob);
        $ofBob = $this->givenLog($bob, 5, '2026-11-17', '2026-11-17 08:00:00', 2);

        $result = $this->sync(self::entry(5, '2026-11-17 10:00:00', 9), LogItem::deletion(TestData::uuid(5)), self::entry(6, '2026-11-17 10:00:00'));

        self::assertSame(
            [[TestData::uuid(5), Rejection::NotYours], [TestData::uuid(5), Rejection::NotYours], [TestData::uuid(6), null]],
            self::answers($result),
        );
        self::assertEquals($ofBob, $this->smokeLogs->find(TestData::uuid(5)));
        self::assertSame([['2026-11-17', 1, 9, 8, false, false]], self::views($result->days));
        self::assertSame([], $this->dayRecords->ofUser($bob->id));
    }

    public function test_FR_LOG_02_a_log_more_than_five_minutes_ahead_of_the_servers_clock_is_refused(): void
    {
        $result = $this->sync(self::entry(1, '2026-11-17 12:05:00'), self::entry(2, '2026-11-17 12:05:01'));

        self::assertSame([[TestData::uuid(1), null], [TestData::uuid(2), Rejection::InFuture]], self::answers($result));
        self::assertNull($this->smokeLogs->find(TestData::uuid(2)));
        self::assertSame([['2026-11-17', 1, 9, 8, false, false]], self::views($result->days));
        self::assertSame(300, SyncLogs::FUTURE_TOLERANCE_SECONDS);
    }

    public function test_FR_LOG_03_a_log_before_the_first_day_of_the_plan_is_refused(): void
    {
        $bob = $this->givenUser('bob@example.org');
        $this->givenOnboarded($bob, '2026-11-16');

        // The 16th begins at 23:00 UTC on the 15th.
        $result = $this->syncLogs()->execute($bob->id, [self::entry(1, '2026-11-15 22:59:59'), self::entry(2, '2026-11-15 23:00:00')]);

        self::assertSame([[TestData::uuid(1), Rejection::BeforeStart], [TestData::uuid(2), null]], self::answers($result));
        self::assertNull($this->smokeLogs->find(TestData::uuid(1)));
        self::assertSame([['2026-11-16', 1, 9, 8, false, false]], self::views($result->days));
    }

    public function test_FR_LOG_07_a_log_for_a_day_that_is_final_is_refused(): void
    {
        // The 15th is the first open day: it begins at 23:00 UTC on the 14th.
        $result = $this->sync(self::entry(1, '2026-11-14 22:59:59'), self::entry(2, '2026-11-14 23:00:00'));

        self::assertSame([[TestData::uuid(1), Rejection::DayFinalized], [TestData::uuid(2), null]], self::answers($result));
        self::assertNull($this->smokeLogs->find(TestData::uuid(1)));
        self::assertSame([['2026-11-15', 1, 9, 8, false, false]], self::views($result->days));
    }

    public function test_FR_LOG_07_a_log_of_a_final_day_can_neither_be_corrected_nor_moved_nor_deleted(): void
    {
        $final = $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 10:00:00', 2);
        $this->givenDay($this->anna, '2026-11-14', 2, '2026-11-14 21:00:00', TestData::dayResult(7, 30));

        $result = $this->sync(
            self::entry(1, '2026-11-14 10:00:00', 1),
            self::entry(1, '2026-11-17 10:00:00', 2),
            LogItem::deletion(TestData::uuid(1)),
        );

        self::assertSame(
            [[TestData::uuid(1), Rejection::DayFinalized], [TestData::uuid(1), Rejection::DayFinalized], [TestData::uuid(1), Rejection::DayFinalized]],
            self::answers($result),
        );
        self::assertEquals($final, $this->smokeLogs->find(TestData::uuid(1)));
        self::assertSame([], $result->days);
        self::assertNull($this->dayRecords->find($this->anna->id, self::date('2026-11-17')));
    }

    public function test_FR_LOG_07_a_log_of_an_open_day_cannot_be_moved_into_a_final_day(): void
    {
        $this->sync(self::entry(1, '2026-11-17 10:00:00', 2));
        $open = $this->smokeLogs->find(TestData::uuid(1));

        $result = $this->sync(self::entry(1, '2026-11-14 10:00:00', 2));

        self::assertSame([[TestData::uuid(1), Rejection::DayFinalized]], self::answers($result));
        self::assertEquals($open, $this->smokeLogs->find(TestData::uuid(1)));
        self::assertSame([], $result->days);
    }

    public function test_NFR_03_an_item_that_was_stored_before_its_day_became_final_is_still_accepted_when_resent(): void
    {
        // The app never got the answer of its first try, and tries again days later.
        $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 10:00:00', 2, ProductType::Pipe);
        $this->givenDay($this->anna, '2026-11-14', 2, '2026-11-14 21:00:00', TestData::dayResult(7, 30));

        $result = $this->sync(self::entry(1, '2026-11-14 10:00:00', 2, ProductType::Pipe));

        self::assertSame([[TestData::uuid(1), null]], self::answers($result));
        // A final day comes back with the limit it was finalized with.
        self::assertSame([['2026-11-14', 2, 7, 5, true, true]], self::views($result->days));
    }

    public function test_FR_LOG_02_a_day_holds_at_most_300_sessions(): void
    {
        foreach ([10, 11, 12, 13, 14] as $number) {
            $this->sync(self::entry($number, '2026-11-17 08:00:00', 50));
        }
        $this->sync(self::entry(15, '2026-11-17 08:00:00', 40));

        $result = $this->sync(
            self::entry(1, '2026-11-17 09:00:00', 11),
            self::entry(2, '2026-11-17 09:00:00', 10),
            self::entry(3, '2026-11-17 09:00:00', 1),
            // Another day has room.
            self::entry(4, '2026-11-16 09:00:00', 50),
        );

        self::assertSame(
            [[TestData::uuid(1), Rejection::DayFull], [TestData::uuid(2), null], [TestData::uuid(3), Rejection::DayFull], [TestData::uuid(4), null]],
            self::answers($result),
        );
        self::assertSame([['2026-11-16', 50, 9, 0, false, false], ['2026-11-17', 300, 9, 0, false, false]], self::views($result->days));
        self::assertSame(300, SyncLogs::MAX_SESSIONS_PER_DAY);
    }

    public function test_FR_LOG_03_a_correction_counts_against_the_300_without_the_old_value_of_the_log(): void
    {
        foreach ([10, 11, 12, 13, 14] as $number) {
            $this->sync(self::entry($number, '2026-11-17 08:00:00', 50));
        }
        $this->sync(self::entry(15, '2026-11-17 08:00:00', 40));

        // 290 are stored. Raising the log of 40 to 50 gives exactly 300, lowering one of 50 gives room again.
        $result = $this->sync(
            self::entry(15, '2026-11-17 08:00:00', 50),
            self::entry(1, '2026-11-17 09:00:00', 1),
            self::entry(10, '2026-11-17 08:00:00', 49),
            self::entry(1, '2026-11-17 09:00:00', 1),
            LogItem::deletion(TestData::uuid(11)),
            self::entry(2, '2026-11-17 09:00:00', 50),
        );

        self::assertSame(
            [
                [TestData::uuid(15), null], [TestData::uuid(1), Rejection::DayFull], [TestData::uuid(10), null], [TestData::uuid(1), null],
                [TestData::uuid(11), null], [TestData::uuid(2), null],
            ],
            self::answers($result),
        );
        self::assertSame([['2026-11-17', 300, 9, 0, false, false]], self::views($result->days));
    }

    public function test_FR_LOG_03_a_log_moved_into_a_full_day_is_refused_and_stays_where_it_was(): void
    {
        foreach ([10, 11, 12, 13, 14, 15] as $number) {
            $this->sync(self::entry($number, '2026-11-17 08:00:00', 50));
        }
        $this->sync(self::entry(1, '2026-11-16 09:00:00', 1));

        $result = $this->sync(self::entry(1, '2026-11-17 09:00:00', 1));

        self::assertSame([[TestData::uuid(1), Rejection::DayFull]], self::answers($result));
        self::assertSame(TestData::uuid(1), array_search('2026-11-16', $this->storedDates(), true));
    }

    public function test_NFR_03_every_item_gets_its_own_answer_and_a_refused_one_does_not_stop_the_others(): void
    {
        $result = $this->sync(
            self::entry(1, '2026-11-17 09:00:00', 2),
            self::entry(2, '2026-11-13 09:00:00'),
            self::entry(3, '2026-11-17 13:00:00'),
            self::entry(4, '2026-11-01 09:00:00'),
            self::entry(5, '2026-11-16 09:00:00', 3),
        );

        self::assertSame(
            [
                [TestData::uuid(1), null], [TestData::uuid(2), Rejection::DayFinalized], [TestData::uuid(3), Rejection::InFuture],
                [TestData::uuid(4), Rejection::BeforeStart], [TestData::uuid(5), null],
            ],
            self::answers($result),
        );
        self::assertSame([TestData::uuid(1) => '2026-11-17', TestData::uuid(5) => '2026-11-16'], $this->storedDates());
        self::assertSame([['2026-11-16', 3, 9, 6, false, false], ['2026-11-17', 2, 9, 7, false, false]], self::views($result->days));
    }

    public function test_FR_LOG_04_a_checked_in_day_can_still_be_corrected_and_stays_checked_in(): void
    {
        $this->givenLog($this->anna, 1, '2026-11-16', '2026-11-16 10:00:00', 2);
        $this->givenDay($this->anna, '2026-11-16', 2, '2026-11-16 21:30:00');

        $result = $this->sync(self::entry(2, '2026-11-16 19:00:00', 4), LogItem::deletion(TestData::uuid(1)));

        self::assertSame([['2026-11-16', 4, 9, 5, true, false]], self::views($result->days));
        self::assertEquals(
            [new DayRecord($this->anna->id, self::date('2026-11-16'), 4, TestData::time('2026-11-16 21:30:00'))],
            $this->dayRecords->ofUser($this->anna->id),
        );
    }

    public function test_FR_LOG_06_going_over_the_limit_is_stored_like_anything_else_and_nothing_is_left_below_zero(): void
    {
        $result = $this->sync(self::entry(1, '2026-11-17 09:00:00', 12));

        self::assertSame([[TestData::uuid(1), null]], self::answers($result));
        self::assertSame([['2026-11-17', 12, 9, 0, false, false]], self::views($result->days));
    }

    public function test_FR_I18N_02_each_day_has_the_limit_of_the_plan_and_the_baseline_that_were_valid_on_it(): void
    {
        // From the 16th on the baseline is 10.0, from the 17th on the taper speed is "fast".
        $this->baselines->append(new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(100), BaselineSource::Weekly, 10.0, 10.0, 6, 6, 'v1'));
        $this->planHistory->record(new PlanTerms($this->anna->id, self::date('2026-11-17'), Goal::reduceTo(5), TaperSpeed::Fast, 'v1'));

        $result = $this->sync(self::entry(1, '2026-11-15 09:00:00'), self::entry(2, '2026-11-16 09:00:00'), self::entry(3, '2026-11-17 09:00:00'));

        self::assertSame(
            [
                // 80 % of 12, 80 % of 10, 70 % of 10.
                ['2026-11-15', 1, 9, 8, false, false],
                ['2026-11-16', 1, 8, 7, false, false],
                ['2026-11-17', 1, 7, 6, false, false],
            ],
            self::views($result->days),
        );
    }

    public function test_FR_I18N_02_a_plan_that_counts_from_tomorrow_does_not_change_todays_limit(): void
    {
        $this->planHistory->record(new PlanTerms($this->anna->id, self::date('2026-11-18'), Goal::quit(), TaperSpeed::Fast, 'v1'));

        $result = $this->sync(self::entry(1, '2026-11-17 09:00:00'));

        self::assertSame([['2026-11-17', 1, 9, 8, false, false]], self::views($result->days));
    }

    public function test_FR_PLAN_01_in_reduce_mode_the_limit_of_a_day_never_goes_below_the_target(): void
    {
        $this->baselines->append(new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(40), BaselineSource::Weekly, 4.0, 4.0, 6, 6, 'v1'));

        $result = $this->sync(self::entry(1, '2026-11-17 09:00:00'));

        // 80 % of 4.0 is 3, the target is 5.
        self::assertSame([['2026-11-17', 1, 5, 4, false, false]], self::views($result->days));
    }

    public function test_FR_ACC_06_without_a_plan_nothing_can_be_logged(): void
    {
        $bob = $this->givenUser('bob@example.org');

        try {
            $this->syncLogs()->execute($bob->id, [self::entry(1, '2026-11-17 09:00:00')]);
            self::fail('Expected the sync to be refused before the onboarding.');
        } catch (PlanRequired) {
        }

        self::assertSame([], $this->smokeLogs->all());
        self::assertSame([], $this->dayRecords->ofUser($bob->id));
    }

    public function test_FR_ACC_08_an_account_that_was_deleted_in_the_meantime_logs_nothing(): void
    {
        $this->expectException(AccountNotFound::class);

        $this->syncLogs()->execute(TestData::userId(999), [self::entry(1, '2026-11-17 09:00:00')]);
    }

    public function test_NFR_03_a_batch_is_one_transaction_that_locks_the_account_first(): void
    {
        $this->sync(self::entry(1, '2026-11-17 09:00:00'), self::entry(2, '2026-11-13 09:00:00'), self::entry(3, '2026-11-16 09:00:00'));

        self::assertSame(1, $this->transactions->commits);
        self::assertSame(0, $this->transactions->rollbacks);
        self::assertSame('users', $this->transactions->locks[0] ?? null);
    }

    public function test_FR_LOG_02_a_batch_holds_at_most_200_items(): void
    {
        $items = [];
        for ($number = 1; $number <= 201; $number++) {
            $items[] = self::entry($number, '2026-11-17 09:00:00');
        }

        $full = $this->syncLogs()->execute($this->anna->id, array_slice($items, 0, 200));

        self::assertCount(200, $full->items);
        self::assertSame([['2026-11-17', 200, 9, 0, false, false]], self::views($full->days));
        self::assertSame(200, SyncLogs::MAX_ITEMS);

        $this->expectException(InvalidArgumentException::class);
        $this->syncLogs()->execute($this->anna->id, $items);
    }

    public function test_an_item_is_a_log_or_a_deletion(): void
    {
        $entry = LogItem::entry(TestData::uuid(1), TestData::time('2026-11-17 09:00:00'), ProductType::Shisha, 50);
        $deletion = LogItem::deletion(TestData::uuid(2));

        self::assertSame([TestData::uuid(1), false, ProductType::Shisha, 50], [$entry->id, $entry->deleted, $entry->productType, $entry->count]);
        self::assertEquals(TestData::time('2026-11-17 09:00:00'), $entry->smokedAt);
        self::assertSame([TestData::uuid(2), true, null, null, null], [$deletion->id, $deletion->deleted, $deletion->smokedAt, $deletion->productType, $deletion->count]);
    }

    public function test_FR_LOG_02_a_log_counts_1_to_50_sessions(): void
    {
        self::assertSame(1, LogItem::entry(TestData::uuid(1), TestData::time('2026-11-17 09:00:00'), ProductType::Cigarette, 1)->count);

        foreach ([0, -1, 51] as $count) {
            try {
                LogItem::entry(TestData::uuid(1), TestData::time('2026-11-17 09:00:00'), ProductType::Cigarette, $count);
                self::fail("Expected a count of {$count} to be refused.");
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function test_FR_LOG_03_a_day_past_its_48_hours_takes_nothing_more_even_before_the_cron_job_has_made_it_final(): void
    {
        // The 15th ended at 23:00 UTC on the 15th. The cron job has not run since.
        $this->clock->travelTo(TestData::time('2026-11-17 22:59:59'));
        $inTime = $this->sync(self::entry(1, '2026-11-15 12:00:00', 2));
        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));

        $late = $this->sync(
            self::entry(2, '2026-11-15 13:00:00'),
            self::entry(1, '2026-11-15 12:00:00', 3),
            self::entry(1, '2026-11-16 12:00:00', 2),
            LogItem::deletion(TestData::uuid(1)),
            // Stored exactly like this a second ago: still accepted (NFR-03).
            self::entry(1, '2026-11-15 12:00:00', 2),
            self::entry(3, '2026-11-16 12:00:00'),
        );

        self::assertSame([[TestData::uuid(1), null]], self::answers($inTime));
        self::assertSame(
            [
                [TestData::uuid(2), Rejection::DayFinalized], [TestData::uuid(1), Rejection::DayFinalized], [TestData::uuid(1), Rejection::DayFinalized],
                [TestData::uuid(1), Rejection::DayFinalized], [TestData::uuid(1), null], [TestData::uuid(3), null],
            ],
            self::answers($late),
        );
        self::assertSame([TestData::uuid(1) => '2026-11-15', TestData::uuid(3) => '2026-11-16'], $this->storedDates());
        self::assertSame(2, $this->smokeLogs->find(TestData::uuid(1))?->count);
    }

    public function test_NFR_03_an_item_with_two_reasons_is_answered_with_the_first_in_the_order_of_the_design(): void
    {
        $bob = $this->givenUser('bob@example.org');
        $this->givenOnboarded($bob);
        $this->givenLog($bob, 5, '2026-11-17', '2026-11-17 08:00:00', 2);
        foreach ([10, 11, 12, 13, 14, 15] as $number) {
            $this->sync(self::entry($number, '2026-11-17 08:00:00', 50));
        }
        $this->sync(self::entry(20, '2026-11-16 08:00:00'), LogItem::deletion(TestData::uuid(20)));
        $deleted = $this->smokeLogs->find(TestData::uuid(20));

        $result = $this->sync(
            // Somebody else's id, and an hour ahead: not_yours.
            self::entry(5, '2026-11-17 13:00:00'),
            // A deleted log, and an hour ahead: accepted, nothing changes.
            self::entry(20, '2026-11-17 13:00:00'),
            // An hour ahead, on a day that is full: in_future.
            self::entry(1, '2026-11-17 13:00:00'),
            // Before the plan, which is also before the first open day: before_start.
            self::entry(2, '2026-11-09 12:00:00'),
        );

        self::assertSame(
            [[TestData::uuid(5), Rejection::NotYours], [TestData::uuid(20), null], [TestData::uuid(1), Rejection::InFuture], [TestData::uuid(2), Rejection::BeforeStart]],
            self::answers($result),
        );
        self::assertEquals($deleted, $this->smokeLogs->find(TestData::uuid(20)));
    }

    public function test_FR_LOG_02_an_id_is_the_same_log_in_upper_and_in_lower_case(): void
    {
        $upper = 'A3BB189E-8BF9-4888-9912-ACE4E6543002';

        self::assertSame(strtolower($upper), LogItem::entry($upper, TestData::time('2026-11-17 09:00:00'), ProductType::Cigarette, 1)->id);
        self::assertSame(strtolower($upper), LogItem::deletion($upper)->id);

        $this->sync(LogItem::entry($upper, TestData::time('2026-11-17 09:00:00'), ProductType::Cigarette, 2));
        $result = $this->sync(LogItem::entry(strtolower($upper), TestData::time('2026-11-17 09:00:00'), ProductType::Cigarette, 3));

        self::assertSame([['2026-11-17', 3, 9, 6, false, false]], self::views($result->days));
        self::assertCount(1, $this->smokeLogs->all());
    }

    public function test_a_plan_without_progress_is_damaged_data_and_stops_the_sync(): void
    {
        $this->progressRecords->eraseOfUser($this->anna->id);

        try {
            $this->sync(self::entry(1, '2026-11-17 09:00:00'));
            self::fail('Expected the damaged data to stop the request.');
        } catch (LogicException $damaged) {
            self::assertSame('A plan exists without progress.', $damaged->getMessage());
        }

        self::assertSame([], $this->smokeLogs->all());
    }

    public function test_the_refusals_of_the_use_case_say_what_is_wrong(): void
    {
        $messages = [];
        try {
            LogItem::entry(TestData::uuid(1), TestData::time('2026-11-17 09:00:00'), ProductType::Cigarette, 51);
        } catch (InvalidArgumentException $refused) {
            $messages[] = $refused->getMessage();
        }
        try {
            $this->syncLogs()->execute($this->anna->id, array_fill(0, 201, self::entry(1, '2026-11-17 09:00:00')));
        } catch (InvalidArgumentException $refused) {
            $messages[] = $refused->getMessage();
        }
        try {
            $this->syncLogs()->execute($this->givenUser('carol@example.org')->id, [self::entry(1, '2026-11-17 09:00:00')]);
        } catch (PlanRequired $refused) {
            $messages[] = $refused->getMessage();
        }

        self::assertSame(['A log holds 1 to 50 sessions.', 'A sync holds at most 200 items.', 'The user has no plan yet.'], $messages);
    }

    public function test_FR_LOG_02_a_stored_log_holds_1_to_50_sessions_too(): void
    {
        $log = static fn (int $count): SmokeLog => new SmokeLog(
            TestData::uuid(1), TestData::userId(1), TestData::time('2026-11-17 09:00:00'), TestData::date('2026-11-17'), ProductType::Cigarette, $count,
            TestData::time('2026-11-17 09:00:00'), TestData::time('2026-11-17 09:00:00'),
        );

        self::assertSame([1, 50], [$log(1)->count, $log(50)->count]);
        foreach ([0, 51, -3] as $count) {
            try {
                $log($count);
                self::fail("Expected a stored count of {$count} to be refused.");
            } catch (InvalidArgumentException) {
            }
        }
    }

    private function sync(LogItem ...$items): SyncResult
    {
        return $this->syncLogs()->execute($this->anna->id, array_values($items));
    }

    private static function entry(int $number, string $smokedAt, int $count = 1, ProductType $productType = ProductType::Cigarette): LogItem
    {
        return LogItem::entry(TestData::uuid($number), TestData::time($smokedAt), $productType, $count);
    }

    /**
     * @return list<array{string, Rejection|null}> Per item, in the order of the batch: id and why it was refused.
     */
    private static function answers(SyncResult $result): array
    {
        return array_map(static fn ($item): array => [$item->id, $item->rejection], $result->items);
    }

    /**
     * @return array<string, string> Id => local date of the logs that are stored and not deleted.
     */
    private function storedDates(): array
    {
        $dates = [];
        foreach ($this->smokeLogs->all() as $log) {
            if ($log->deletedAt === null) {
                $dates[$log->id] = $log->localDate->value;
            }
        }
        ksort($dates);

        return $dates;
    }
}
