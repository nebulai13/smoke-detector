<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Day;

use LogicException;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Application\Day\DayResult;
use SmokeDetector\Application\Day\FinalizeDays;
use SmokeDetector\Application\Day\FinalizeReport;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\PlanTerms;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\DayEventType;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Streak\StreakState;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * `days:finalize` (HLD §4.6 "Day finalization", §5.2; FR-LOG-03, FR-GAME-01 to -05, NFR-04): a day
 * becomes final 48 hours after it ended. Then, and only then, it changes the stored streaks, XP and
 * level of its user, in calendar order, each day in a transaction of its own.
 */
final class FinalizeDaysTest extends LoggingUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_LOG_03_a_day_becomes_final_48_hours_after_it_ended_and_not_a_second_earlier(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenLog($this->anna, 100, '2026-11-14', '2026-11-14 10:00:00', 3);
        $this->givenDay($this->anna, '2026-11-14', 3, '2026-11-14 21:00:00');
        $before = $this->progressRecords->find($this->anna->id);
        self::assertNotNull($before);

        // The 14th ended at 23:00 UTC, midnight in Vienna.
        $this->clock->travelTo(TestData::time('2026-11-16 22:59:59'));
        $early = $this->finalizeDays()->execute();

        self::assertSame([0, 0, 0, false], self::report($early));
        self::assertFalse($this->dayRecords->find($this->anna->id, self::date('2026-11-14'))?->isFinal());
        self::assertEquals($before, $this->progressRecords->find($this->anna->id));

        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $onTime = $this->finalizeDays()->execute();

        self::assertSame([1, 0, 0, false], self::report($onTime));
        self::assertEquals(
            new DayRecord(
                $this->anna->id,
                self::date('2026-11-14'),
                3,
                TestData::time('2026-11-14 21:00:00'),
                new DayResult(
                    9,
                    30,
                    [new DayEvent(DayEventType::CheckedIn), new DayEvent(DayEventType::UnderLimitDay, 1), new DayEvent(DayEventType::StreakExtended, 1)],
                    false,
                    TestData::time('2026-11-16 23:00:00'),
                    'v1',
                ),
            ),
            $this->dayRecords->find($this->anna->id, self::date('2026-11-14')),
        );
        self::assertEquals(
            [
                new XpEvent(SequentialIdGenerator::id(2), $this->anna->id, self::date('2026-11-14'), XpSource::CheckIn, 10, null, TestData::time('2026-11-16 23:00:00')),
                new XpEvent(SequentialIdGenerator::id(3), $this->anna->id, self::date('2026-11-14'), XpSource::UnderLimit, 20, null, TestData::time('2026-11-16 23:00:00')),
            ],
            $this->xpLedger->ofUser($this->anna->id),
        );
        self::assertEquals(
            new ProgressRecord(
                $this->anna->id,
                new UserProgress(new StreakState(1, 1, 1, 1, 0), 30),
                0,
                // The 15th is next: it ends at 23:00 UTC on the 15th and is final 48 hours later.
                self::date('2026-11-15'),
                TestData::time('2026-11-17 23:00:00'),
                $before->baselineDueAt,
            ),
            $this->progressRecords->find($this->anna->id),
        );
        self::assertSame(172_800, FinalizeDays::GRACE_SECONDS);
    }

    public function test_FR_GAME_02_a_day_nobody_touched_gets_its_row_as_a_missed_day_with_nothing_earned(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));

        $report = $this->finalizeDays()->execute();

        self::assertSame([1, 0, 0, false], self::report($report));
        self::assertEquals(
            [new DayRecord($this->anna->id, self::date('2026-11-14'), 0, null, new DayResult(9, 0, [], false, TestData::time('2026-11-16 23:00:00'), 'v1'))],
            $this->dayRecords->ofUser($this->anna->id),
        );
        self::assertSame([], $this->xpLedger->ofUser($this->anna->id));
        self::assertEquals(new UserProgress(StreakState::initial(), 0), $this->progressRecords->find($this->anna->id)?->progress);
        self::assertEquals(self::date('2026-11-15'), $this->progressRecords->find($this->anna->id)?->nextDay);
    }

    public function test_NFR_04_the_count_of_a_final_day_is_what_its_logs_say(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenLog($this->anna, 100, '2026-11-14', '2026-11-14 10:00:00', 2);
        $this->givenLog($this->anna, 101, '2026-11-14', '2026-11-14 11:00:00', 1);
        $this->givenLog($this->anna, 102, '2026-11-14', '2026-11-14 12:00:00', 4, deletedAt: '2026-11-14 12:30:00');
        $this->givenLog($this->anna, 103, '2026-11-15', '2026-11-15 12:00:00', 7);
        // A stored count that is wrong, for whatever reason.
        $this->givenDay($this->anna, '2026-11-14', 5, '2026-11-14 21:00:00');
        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));

        $this->finalizeDays()->execute();

        self::assertSame(3, $this->dayRecords->find($this->anna->id, self::date('2026-11-14'))?->count);
    }

    public function test_FR_GAME_02_days_are_finalized_in_calendar_order_and_each_builds_on_the_one_before(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10');
        // Smoke-free, over the limit, missed, exactly at the limit, under it.
        $this->givenDay($this->anna, '2026-11-10', 0, '2026-11-10 21:00:00');
        $this->givenLog($this->anna, 111, '2026-11-11', '2026-11-11 10:00:00', 12);
        $this->givenDay($this->anna, '2026-11-11', 12, '2026-11-11 21:00:00');
        $this->givenLog($this->anna, 112, '2026-11-12', '2026-11-12 10:00:00', 4);
        $this->givenDay($this->anna, '2026-11-12', 4);
        $this->givenLog($this->anna, 113, '2026-11-13', '2026-11-13 10:00:00', 9);
        $this->givenDay($this->anna, '2026-11-13', 9, '2026-11-14 08:00:00');
        $this->givenLog($this->anna, 114, '2026-11-14', '2026-11-14 10:00:00', 2);
        $this->givenDay($this->anna, '2026-11-14', 2, '2026-11-14 21:00:00');

        // Noon on the 17th: the 14th is the last day that ended 48 hours ago.
        $report = $this->finalizeDays()->execute();

        self::assertSame([5, 0, 0, false], self::report($report));
        $stored = [];
        foreach ($this->dayRecords->ofUser($this->anna->id) as $day) {
            self::assertNotNull($day->result, $day->date->value);
            $stored[$day->date->value] = [$day->result->xp, self::events($day->result->events)];
        }
        self::assertSame(
            [
                '2026-11-10' => [70, ['checked_in', 'under_limit_day:1', 'smoke_free_day', 'streak_extended:1']],
                '2026-11-11' => [10, ['checked_in', 'streak_extended:2']],
                '2026-11-12' => [0, ['streak_broken:2']],
                // 110 XP in all: level 1 begins at 100.
                '2026-11-13' => [30, ['checked_in', 'under_limit_day:1', 'streak_extended:1', 'level_up:1']],
                '2026-11-14' => [30, ['checked_in', 'under_limit_day:2', 'streak_extended:2']],
            ],
            $stored,
        );
        $progress = $this->progressRecords->find($this->anna->id);
        self::assertNotNull($progress);
        self::assertEquals(new UserProgress(new StreakState(2, 2, 2, 2, 0), 140), $progress->progress);
        self::assertSame(1, $progress->level);
        self::assertEquals([self::date('2026-11-15'), TestData::time('2026-11-17 23:00:00')], [$progress->nextDay, $progress->finalizeDueAt]);
    }

    public function test_FR_GAME_01_the_xp_total_is_the_sum_of_the_ledger_and_every_award_is_one_entry(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10');
        foreach (['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14'] as $date) {
            $this->givenDay($this->anna, $date, 0, "{$date} 21:00:00");
        }

        $this->finalizeDays()->execute();

        $ledger = $this->xpLedger->ofUser($this->anna->id);
        self::assertSame(
            array_fill(0, 5, ['check_in' => 10, 'under_limit' => 20, 'smoke_free' => 40]),
            array_map(
                static fn (array $ofDay): array => array_column($ofDay, 'amount', 'source'),
                array_chunk(array_map(static fn (XpEvent $event): array => ['source' => $event->source->value, 'amount' => $event->amount], $ledger), 3),
            ),
        );
        self::assertSame(
            ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14'],
            array_values(array_unique(array_map(static fn (XpEvent $event): string => $event->date->value, $ledger))),
        );
        self::assertCount(15, array_unique(array_map(static fn (XpEvent $event): string => $event->id, $ledger)));
        self::assertSame(350, array_sum(array_map(static fn (XpEvent $event): int => $event->amount, $ledger)));
        $progress = $this->progressRecords->find($this->anna->id);
        self::assertNotNull($progress);
        // 350 XP is level 2 (300 to 599).
        self::assertSame([350, 2], [$progress->progress->totalXp, $progress->level]);
    }

    public function test_FR_GAME_04_a_freeze_is_used_for_a_missed_day_and_the_day_says_so(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-14', streaks: [8, 8, 8, 8, 1], totalXp: 1000, level: 4);
        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));

        $this->finalizeDays()->execute();

        self::assertEquals(
            new DayResult(9, 0, [new DayEvent(DayEventType::FreezeUsed, 0)], true, TestData::time('2026-11-16 23:00:00'), 'v1'),
            $this->dayRecords->find($this->anna->id, self::date('2026-11-14'))?->result,
        );
        self::assertEquals(new UserProgress(new StreakState(8, 8, 0, 8, 0), 1000), $this->progressRecords->find($this->anna->id)?->progress);
        self::assertSame(4, $this->progressRecords->find($this->anna->id)?->level);
    }

    public function test_FR_GAME_04_a_streak_milestone_pays_its_xp_once_with_the_length_as_detail(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-14', streaks: [6, 6, 6, 6, 0], totalXp: 180, level: 1);
        $this->givenDay($this->anna, '2026-11-14', 0, '2026-11-14 21:00:00');
        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));

        $this->finalizeDays()->execute();

        $result = $this->dayRecords->find($this->anna->id, self::date('2026-11-14'))?->result;
        self::assertNotNull($result);
        self::assertSame(
            [120, false, ['checked_in', 'under_limit_day:7', 'smoke_free_day', 'streak_extended:7', 'streak_milestone:7', 'freeze_earned:1', 'level_up:2']],
            [$result->xp, $result->freezeUsed, self::events($result->events)],
        );
        self::assertSame(
            [['check_in', 10, null], ['under_limit', 20, null], ['smoke_free', 40, null], ['streak_milestone', 50, 7]],
            array_map(static fn (XpEvent $event): array => [$event->source->value, $event->amount, $event->detail], $this->xpLedger->ofUser($this->anna->id)),
        );
        self::assertEquals(new UserProgress(new StreakState(7, 7, 7, 7, 1), 300), $this->progressRecords->find($this->anna->id)?->progress);
        self::assertSame(2, $this->progressRecords->find($this->anna->id)?->level);
    }

    public function test_NFR_04_a_day_is_final_with_the_limit_of_the_plan_and_the_baseline_that_were_valid_on_it(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-13');
        // From the 14th on: a baseline of 10.0 and the taper speed "fast".
        $this->baselines->append(new BaselineEntry($this->anna->id, self::date('2026-11-14'), Baseline::fromTenths(100), BaselineSource::Weekly, 10.0, 10.0, 4, 4, 'v1'));
        $this->planHistory->record(new PlanTerms($this->anna->id, self::date('2026-11-14'), Goal::reduceTo(5), TaperSpeed::Fast, 'v1'));
        foreach (['2026-11-13' => 113, '2026-11-14' => 114] as $date => $logNumber) {
            $this->givenLog($this->anna, $logNumber, $date, "{$date} 10:00:00", 8);
            $this->givenDay($this->anna, $date, 8, "{$date} 21:00:00");
        }

        $this->finalizeDays()->execute();

        $results = [];
        foreach ($this->dayRecords->ofUser($this->anna->id) as $day) {
            $results[$day->date->value] = [$day->result?->dailyLimit, $day->result?->xp];
        }
        // 8 sessions are under 80 % of 12 and over 70 % of 10.
        self::assertSame(['2026-11-13' => [9, 30], '2026-11-14' => [7, 10]], $results);
    }

    public function test_NFR_04_a_second_run_changes_nothing(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10');
        $this->givenDay($this->anna, '2026-11-12', 0, '2026-11-12 21:00:00');
        $this->finalizeDays()->execute();
        $days = $this->dayRecords->ofUser($this->anna->id);
        $progress = $this->progressRecords->find($this->anna->id);
        $ledger = $this->xpLedger->ofUser($this->anna->id);
        $this->clock->advanceSeconds(60);

        $again = $this->finalizeDays()->execute();

        self::assertSame([0, 0, 0, false], self::report($again));
        self::assertEquals($days, $this->dayRecords->ofUser($this->anna->id));
        self::assertEquals($progress, $this->progressRecords->find($this->anna->id));
        self::assertEquals($ledger, $this->xpLedger->ofUser($this->anna->id));
    }

    public function test_FR_LOG_03_a_stored_due_instant_that_is_too_early_finalizes_nothing_and_is_put_right(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-16');
        $stored = $this->progressRecords->find($this->anna->id);
        self::assertNotNull($stored);
        $this->progressRecords->save(new ProgressRecord(
            $stored->userId, $stored->progress, $stored->level, $stored->nextDay, TestData::time('2026-11-01 00:00:00'), $stored->baselineDueAt,
        ));

        // Noon on the 17th: the 16th ended 13 hours ago.
        $report = $this->finalizeDays()->execute();

        self::assertSame([0, 0, 0, false], self::report($report));
        self::assertSame([], $this->dayRecords->ofUser($this->anna->id));
        self::assertEquals($stored, $this->progressRecords->find($this->anna->id));
    }

    public function test_the_run_takes_no_new_day_after_20_seconds_and_says_that_work_is_left(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10');
        // Every day costs 10 seconds here.
        $this->progressRecords->afterSave = fn () => $this->clock->advanceSeconds(10);

        $report = $this->finalizeDays()->execute();

        self::assertSame([2, 0, 0, true], self::report($report));
        self::assertEquals(self::date('2026-11-12'), $this->progressRecords->find($this->anna->id)?->nextDay);
        self::assertSame(20, FinalizeDays::BUDGET_SECONDS);

        // The next run, a minute later, goes on where this one stopped.
        $this->progressRecords->afterSave = fn () => $this->clock->advanceSeconds(7);
        $next = $this->finalizeDays()->execute();

        // 7, 14 and 21 seconds: the third day was begun inside the 20 seconds.
        self::assertSame([3, 0, 0, false], self::report($next));
        self::assertEquals(self::date('2026-11-15'), $this->progressRecords->find($this->anna->id)?->nextDay);
    }

    public function test_the_user_who_waits_longest_comes_first(): void
    {
        $bob = $this->givenUser('bob@example.org');
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenOnboarded($bob, '2026-11-12');
        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $this->progressRecords->afterSave = fn () => $this->clock->advanceSeconds(10);

        $report = $this->finalizeDays()->execute();

        // Time for two days: both go to Bob, whose 12th is due since the 14th.
        self::assertSame([2, 0, 0, true], self::report($report));
        self::assertEquals(self::date('2026-11-14'), $this->progressRecords->find($bob->id)?->nextDay);
        self::assertEquals(self::date('2026-11-14'), $this->progressRecords->find($this->anna->id)?->nextDay);
        self::assertSame([], $this->dayRecords->ofUser($this->anna->id));
    }

    public function test_every_user_who_is_due_is_served_however_many_there_are(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $users = [$this->anna];
        for ($number = 1; $number <= FinalizeDays::BATCH_SIZE + 1; $number++) {
            $user = $this->givenUser("user{$number}@example.org");
            $this->givenOnboarded($user, '2026-11-14');
            $users[] = $user;
        }
        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));

        $report = $this->finalizeDays()->execute();

        self::assertSame([FinalizeDays::BATCH_SIZE + 2, 0, 0, false], self::report($report));
        foreach ($users as $user) {
            self::assertEquals(self::date('2026-11-15'), $this->progressRecords->find($user->id)?->nextDay);
        }
        self::assertGreaterThan(1, FinalizeDays::BATCH_SIZE);
    }

    public function test_FR_ACC_08_progress_without_an_account_is_passed_over_and_the_run_ends(): void
    {
        // Cannot happen in the database, where the row goes with the account.
        $this->progressRecords->save(TestData::progress(999, finalizeDueAt: '2026-11-01 00:00:00'));
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));

        $report = $this->finalizeDays()->execute();

        self::assertSame([1, 0, 0, false], self::report($report));
        self::assertEquals(TestData::progress(999, finalizeDueAt: '2026-11-01 00:00:00'), $this->progressRecords->find(TestData::userId(999)));
        self::assertLessThan(10, $this->progressRecords->dueCalls);
    }

    public function test_a_user_whose_day_cannot_be_finalized_does_not_stop_the_others(): void
    {
        $bob = $this->givenUser('bob@example.org');
        foreach ([$this->anna, $bob] as $user) {
            $this->givenOnboarded($user, '2026-11-14');
            $this->givenDay($user, '2026-11-14', 0, '2026-11-14 21:00:00');
        }
        $beforeAnna = $this->progressRecords->find($this->anna->id);
        // The id the first XP event of the run will get is taken: storing it fails. Anna comes
        // first (both wait equally long, and her id is the lower one).
        $this->xpLedger->append(new XpEvent(SequentialIdGenerator::id(3), $bob->id, self::date('2026-11-01'), XpSource::Reaction, 2, null, $this->now()));
        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));

        $report = $this->finalizeDays()->execute();

        self::assertSame([1, 0, 1, false], self::report($report));
        // Nothing of Anna's day is stored: the day is one transaction.
        self::assertFalse($this->dayRecords->find($this->anna->id, self::date('2026-11-14'))?->isFinal());
        self::assertEquals($beforeAnna, $this->progressRecords->find($this->anna->id));
        self::assertSame([], $this->xpLedger->ofUser($this->anna->id));
        self::assertTrue($this->dayRecords->find($bob->id, self::date('2026-11-14'))?->isFinal());
        self::assertSame(70, $this->progressRecords->find($bob->id)?->progress->totalXp);
        // The log says that it happened and what kind of failure it was, and nothing about whom.
        self::assertSame(
            [['level' => 'error', 'message' => 'The days of a user could not be finalized.', 'context' => ['exception' => LogicException::class]]],
            $this->logger->records,
        );
    }

    public function test_NFR_04_each_day_is_a_transaction_of_its_own_that_locks_the_account_first(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10');

        $this->finalizeDays()->execute();

        self::assertGreaterThanOrEqual(5, $this->transactions->commits);
        self::assertSame(0, $this->transactions->rollbacks);
        self::assertSame(['users'], array_values(array_unique($this->transactions->locks)));
        self::assertGreaterThanOrEqual(5, count($this->transactions->locks));
    }

    public function test_progress_without_a_plan_is_damaged_data_and_counts_as_a_user_that_failed(): void
    {
        // Anna has an account and progress that is due, and no plan.
        $this->progressRecords->save(new ProgressRecord(
            $this->anna->id, UserProgress::initial(), 0, self::date('2026-11-10'), TestData::time('2026-11-12 23:00:00'), TestData::time('2026-11-22 23:00:00'),
        ));

        $report = $this->finalizeDays()->execute();

        self::assertSame([0, 0, 1, false], self::report($report));
        self::assertSame(
            [['level' => 'error', 'message' => 'The days of a user could not be finalized.', 'context' => ['exception' => LogicException::class]]],
            $this->logger->records,
        );
    }

    public function test_FR_ACC_08_progress_that_vanishes_while_the_run_waits_for_the_account_counts_as_a_user_that_failed(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        // Stands for what another request did while this one waited for the lock.
        $this->users->beforeLock = fn () => $this->progressRecords->eraseOfUser($this->anna->id);

        $report = $this->finalizeDays()->execute();

        self::assertSame([0, 0, 1, false], self::report($report));
        self::assertSame(
            [['level' => 'error', 'message' => 'The days of a user could not be finalized.', 'context' => ['exception' => LogicException::class]]],
            $this->logger->records,
        );
        self::assertSame([], $this->dayRecords->ofUser($this->anna->id));
    }

    public function test_FR_PLAN_04_a_baseline_that_is_not_due_yet_waits_also_while_days_are_finalized(): void
    {
        // The 12th is due. The baseline of Monday the 16th is due an hour from now, which is the
        // same instant the 13th will be due.
        $this->givenOnboarded($this->anna, '2026-11-09', '2026-11-12');
        $this->givenProgress($this->anna, '2026-11-12', baselineDay: '2026-11-16');
        $this->clock->travelTo(TestData::time('2026-11-15 22:00:00'));

        $report = $this->finalizeDays()->execute();

        self::assertSame([1, 0, 0, false], self::report($report));
        self::assertCount(1, $this->baselines->ofUser($this->anna->id));
        self::assertEquals(self::date('2026-11-13'), $this->progressRecords->find($this->anna->id)?->nextDay);
    }

    /**
     * @return array{int, int, int, bool} Days, baselines, users that failed, out of time.
     */
    private static function report(FinalizeReport $report): array
    {
        return [$report->days, $report->baselines, $report->failedUsers, $report->outOfTime];
    }
}
