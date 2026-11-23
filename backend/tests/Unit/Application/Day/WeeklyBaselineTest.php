<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Day;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Rules\RuleSet;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;
use SmokeDetector\Tests\Unit\Domain\Rules\RuleTables;

/**
 * The weekly baseline (HLD §4.6 "Day finalization", FR-PLAN-04 to -06): on the user's Monday at
 * 00:00 the cron job calculates the new baseline from the check-ins of the 28 local days before,
 * as they stand at that moment, and appends it to the history, valid from that Monday.
 *
 * Anna did the onboarding on Monday the 9th with a baseline of 12. Her Monday the 16th begins at
 * 23:00 UTC on the 15th. The days up to the 13th are final here, so that only the baseline is due.
 */
final class WeeklyBaselineTest extends LoggingUseCaseTestCase
{
    private const string MONDAY_BEGINS = '2026-11-15 23:00:00';

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_PLAN_04_on_the_users_monday_the_new_baseline_is_appended_with_its_inputs(): void
    {
        $this->givenAWeek(['2026-11-09' => 10, '2026-11-10' => 8, '2026-11-11' => 9, '2026-11-12' => null, '2026-11-13' => 7, '2026-11-14' => 9, '2026-11-15' => 6]);
        // The 12th was not checked in. What was logged on it does not count.
        $this->givenDay($this->anna, '2026-11-12', 20);
        $before = $this->progressRecords->find($this->anna->id);
        self::assertNotNull($before);

        $this->clock->travelTo(TestData::time('2026-11-15 22:59:59'));
        $early = $this->finalizeDays()->execute();

        self::assertSame([0, 0], [$early->days, $early->baselines]);
        self::assertCount(1, $this->baselines->ofUser($this->anna->id));

        $this->clock->travelTo(TestData::time(self::MONDAY_BEGINS));
        $report = $this->finalizeDays()->execute();

        self::assertSame([0, 1, 0, false], [$report->days, $report->baselines, $report->failedUsers, $report->outOfTime]);
        // 49 sessions on 6 checked-in days: 8.17 a day, for the week and for the month alike.
        self::assertEquals(
            new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(82), BaselineSource::Weekly, 8.17, 8.17, 6, 6, 'v1'),
            $this->baselines->latest($this->anna->id),
        );
        self::assertCount(2, $this->baselines->ofUser($this->anna->id));
        // The next one is due a week later. Nothing else of the progress changed.
        $after = $this->progressRecords->find($this->anna->id);
        self::assertNotNull($after);
        self::assertEquals(TestData::time('2026-11-22 23:00:00'), $after->baselineDueAt);
        self::assertEquals(
            [$before->progress, $before->level, $before->nextDay, $before->finalizeDueAt],
            [$after->progress, $after->level, $after->nextDay, $after->finalizeDueAt],
        );
    }

    public function test_FR_PLAN_01_the_new_baseline_gives_the_limit_from_that_monday_on(): void
    {
        $this->givenAWeek(['2026-11-09' => 10, '2026-11-10' => 8, '2026-11-11' => 9, '2026-11-12' => null, '2026-11-13' => 7, '2026-11-14' => 9, '2026-11-15' => 6]);
        $this->clock->travelTo(TestData::time(self::MONDAY_BEGINS));

        $this->finalizeDays()->execute();

        // 80 % of 8.2 is 6.56. Sunday keeps the 9 of the old baseline.
        self::assertSame(
            [['2026-11-15', 6, 9, 3, true, false], ['2026-11-16', 0, 6, 6, false, false]],
            self::views($this->showDays()->execute($this->anna->id, self::date('2026-11-15'), self::date('2026-11-16'))),
        );
    }

    public function test_FR_PLAN_05_with_fewer_than_four_check_ins_in_the_week_the_baseline_stays_and_the_entry_says_why(): void
    {
        $this->givenAWeek(['2026-11-09' => 10, '2026-11-10' => 8, '2026-11-11' => 9]);
        $this->clock->travelTo(TestData::time(self::MONDAY_BEGINS));

        $report = $this->finalizeDays()->execute();

        self::assertSame(1, $report->baselines);
        self::assertEquals(
            new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(120), BaselineSource::UnchangedLowData, 9.0, 9.0, 3, 3, 'v1'),
            $this->baselines->latest($this->anna->id),
        );
    }

    public function test_FR_PLAN_05_a_week_without_any_check_in_has_no_means(): void
    {
        $this->givenAWeek([]);
        $this->clock->travelTo(TestData::time(self::MONDAY_BEGINS));

        $this->finalizeDays()->execute();

        self::assertEquals(
            new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(120), BaselineSource::UnchangedLowData, null, null, 0, 0, 'v1'),
            $this->baselines->latest($this->anna->id),
        );
    }

    public function test_FR_PLAN_06_a_heavy_week_raises_the_baseline_by_the_cap_only(): void
    {
        $this->givenAWeek(
            ['2026-11-09' => 9, '2026-11-10' => 9, '2026-11-11' => 9, '2026-11-12' => 9, '2026-11-13' => 9, '2026-11-14' => 9, '2026-11-15' => 9],
            startingBaseline: 5,
        );
        $this->clock->travelTo(TestData::time(self::MONDAY_BEGINS));

        $this->finalizeDays()->execute();

        // 10 % of 5.0 and the minimum step are both 0.5.
        self::assertEquals(
            new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(55), BaselineSource::Capped, 9.0, 9.0, 7, 7, 'v1'),
            $this->baselines->latest($this->anna->id),
        );
    }

    public function test_FR_PLAN_04_the_week_is_the_last_7_days_and_the_month_the_last_28_and_nothing_older_counts(): void
    {
        // Five weeks of plan, from Monday the 12th of October. Summer time ended in between.
        $this->givenOnboarded($this->anna, '2026-10-12', '2026-11-14');
        $this->givenProgress($this->anna, '2026-11-14', baselineDay: '2026-11-16');
        $date = self::date('2026-10-12');
        while ($date->isBefore(self::date('2026-11-16'))) {
            // 50 in the first week, which is more than 28 days back; 10 in the three weeks after; 6 in the last.
            $count = $date->isBefore(self::date('2026-10-19')) ? 50 : ($date->isBefore(self::date('2026-11-09')) ? 10 : 6);
            $this->givenDay($this->anna, $date->value, $count, "{$date->value} 21:00:00");
            $date = $date->plusDays(1);
        }
        $this->clock->travelTo(TestData::time(self::MONDAY_BEGINS));

        $this->finalizeDays()->execute();

        // W = 6, M = (21 × 10 + 7 × 6) / 28 = 9: 0.7 × 6 + 0.3 × 9 = 6.9.
        self::assertEquals(
            new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(69), BaselineSource::Weekly, 6.0, 9.0, 7, 28, 'v1'),
            $this->baselines->latest($this->anna->id),
        );
    }

    public function test_FR_PLAN_04_a_day_that_is_final_only_after_the_baseline_gets_the_limit_of_the_new_baseline(): void
    {
        // The cron job did not run from Saturday to Friday. Now it catches up, in the order things were due.
        $this->givenAWeek(['2026-11-09' => 10, '2026-11-10' => 8, '2026-11-11' => 9, '2026-11-12' => null, '2026-11-13' => 7, '2026-11-14' => 9, '2026-11-15' => 6]);
        $this->givenDay($this->anna, '2026-11-16', 6, '2026-11-16 21:00:00');
        $this->givenDay($this->anna, '2026-11-17', 7, '2026-11-17 21:00:00');
        $this->givenLog($this->anna, 116, '2026-11-16', '2026-11-16 10:00:00', 6);
        $this->givenLog($this->anna, 117, '2026-11-17', '2026-11-17 10:00:00', 7);
        $this->clock->travelTo(TestData::time('2026-11-20 12:00:00'));

        $report = $this->finalizeDays()->execute();

        // The 14th to the 17th are final (the 18th ended less than 48 hours ago), and one baseline.
        self::assertSame([4, 1, 0, false], [$report->days, $report->baselines, $report->failedUsers, $report->outOfTime]);
        self::assertSame(82, $this->baselines->latest($this->anna->id)?->value->tenths);
        $limits = [];
        foreach ($this->dayRecords->between($this->anna->id, self::date('2026-11-14'), self::date('2026-11-17')) as $day) {
            $limits[$day->date->value] = [$day->result?->dailyLimit, $day->result?->xp];
        }
        // From Monday the 16th the limit is 6: six sessions are under it, seven are over.
        self::assertSame(
            ['2026-11-14' => [9, 30], '2026-11-15' => [9, 30], '2026-11-16' => [6, 30], '2026-11-17' => [6, 10]],
            $limits,
        );
    }

    public function test_FR_PLAN_04_two_mondays_that_were_missed_are_caught_up_one_after_the_other(): void
    {
        $week = [];
        $date = self::date('2026-11-09');
        while ($date->isBefore(self::date('2026-11-23'))) {
            $week[$date->value] = 6;
            $date = $date->plusDays(1);
        }
        $this->givenAWeek($week);
        $this->clock->travelTo(TestData::time('2026-11-24 12:00:00'));

        $report = $this->finalizeDays()->execute();

        self::assertSame(2, $report->baselines);
        self::assertEquals(
            [
                BaselineEntry::onboarding($this->anna->id, self::date('2026-11-09'), Baseline::fromTenths(120), 'v1'),
                new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(60), BaselineSource::Weekly, 6.0, 6.0, 7, 7, 'v1'),
                new BaselineEntry($this->anna->id, self::date('2026-11-23'), Baseline::fromTenths(60), BaselineSource::Weekly, 6.0, 6.0, 7, 14, 'v1'),
            ],
            $this->baselines->ofUser($this->anna->id),
        );
        self::assertEquals(TestData::time('2026-11-29 23:00:00'), $this->progressRecords->find($this->anna->id)?->baselineDueAt);
    }

    public function test_NFR_04_a_second_run_appends_no_second_baseline(): void
    {
        $this->givenAWeek(['2026-11-09' => 10, '2026-11-10' => 8, '2026-11-11' => 9, '2026-11-13' => 7]);
        $this->clock->travelTo(TestData::time(self::MONDAY_BEGINS));
        $this->finalizeDays()->execute();
        $history = $this->baselines->ofUser($this->anna->id);
        $this->clock->advanceSeconds(60);

        $again = $this->finalizeDays()->execute();

        self::assertSame(0, $again->baselines);
        self::assertEquals($history, $this->baselines->ofUser($this->anna->id));
    }

    public function test_the_baseline_counts_against_the_20_seconds_like_a_day(): void
    {
        $this->givenAWeek(['2026-11-09' => 10]);
        // The days from the 14th on are due as well when the cron job comes back on the 20th.
        $this->clock->travelTo(TestData::time('2026-11-20 12:00:00'));
        $this->progressRecords->afterSave = fn () => $this->clock->advanceSeconds(10);

        $report = $this->finalizeDays()->execute();

        // The baseline was due first, then the 14th. After 20 seconds nothing more is begun.
        self::assertSame([1, 1, 0, true], [$report->days, $report->baselines, $report->failedUsers, $report->outOfTime]);
        self::assertEquals(self::date('2026-11-15'), $this->progressRecords->find($this->anna->id)?->nextDay);
    }

    public function test_FR_PLAN_04_a_baseline_and_a_day_that_are_due_at_the_same_instant_are_done_baseline_first(): void
    {
        // Friday the 13th is due exactly when Monday the 16th begins. Its stored count is 5, its
        // one log says 3: the baseline takes the days as they stand, the finalization counts the logs.
        $this->givenAWeek(['2026-11-09' => 6, '2026-11-10' => 6, '2026-11-11' => 6, '2026-11-12' => 6]);
        $this->givenProgress($this->anna, '2026-11-13', baselineDay: '2026-11-16');
        $this->givenDay($this->anna, '2026-11-13', 5, '2026-11-13 21:00:00');
        $this->givenLog($this->anna, 113, '2026-11-13', '2026-11-13 10:00:00', 3);
        $this->clock->travelTo(TestData::time(self::MONDAY_BEGINS));

        $report = $this->finalizeDays()->execute();

        self::assertSame([1, 1], [$report->days, $report->baselines]);
        // 29 sessions on 5 checked-in days. With the day first it would be 27.
        self::assertEquals(
            new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(58), BaselineSource::Weekly, 5.8, 5.8, 5, 5, 'v1'),
            $this->baselines->latest($this->anna->id),
        );
        self::assertSame(3, $this->dayRecords->find($this->anna->id, self::date('2026-11-13'))?->count);
    }

    public function test_FR_PLAN_04_the_windows_come_from_the_rule_table_and_the_next_baseline_is_due_a_calendar_week_later(): void
    {
        // A rule table whose "week" is the last 6 days. The baseline is still a matter of Mondays.
        $table = RuleTables::v1Array();
        self::assertIsArray($table['baseline'] ?? null);
        $table['version'] = 'v1-six-days';
        $table['baseline']['weekDays'] = 6;
        $this->rules = RuleSet::fromArray($table);
        $this->givenAWeek(['2026-11-09' => 20, '2026-11-10' => 6, '2026-11-11' => 6, '2026-11-12' => 6, '2026-11-13' => 6, '2026-11-14' => 6, '2026-11-15' => 6]);
        $this->clock->travelTo(TestData::time(self::MONDAY_BEGINS));

        $this->finalizeDays()->execute();

        // W = 6 over the last 6 days, M = 56 / 7 = 8 over all 7: 0.7 × 6 + 0.3 × 8 = 6.6.
        self::assertEquals(
            new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(66), BaselineSource::Weekly, 6.0, 8.0, 6, 7, 'v1-six-days'),
            $this->baselines->latest($this->anna->id),
        );
        // Monday the 23rd, not six days later.
        self::assertEquals(TestData::time('2026-11-22 23:00:00'), $this->progressRecords->find($this->anna->id)?->baselineDueAt);
    }

    /**
     * Anna's first week: the onboarding on Monday the 9th, the days up to the 13th final, and the
     * first weekly baseline due at the start of Monday the 16th.
     *
     * @param array<string, int|null> $days Date => count of a checked-in day (at most 50, it is one log).
     *                                      Null or left out: no check-in.
     */
    private function givenAWeek(array $days, int $startingBaseline = 12): void
    {
        $this->givenOnboarded($this->anna, '2026-11-09', '2026-11-14', $startingBaseline, Goal::quit());
        $this->givenProgress($this->anna, '2026-11-14', baselineDay: '2026-11-16');
        foreach ($days as $date => $count) {
            if ($count !== null) {
                $this->givenDay($this->anna, $date, $count, "{$date} 21:00:00");
                // The log behind the count: finalization counts the logs again.
                $this->givenLog($this->anna, 1000 + (int) str_replace('-', '', substr($date, 5)), $date, "{$date} 10:00:00", $count);
            }
        }
    }
}
