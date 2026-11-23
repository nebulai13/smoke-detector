<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Competition;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\CalendarPeriod;
use SmokeDetector\Application\Competition\LeagueResult;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * The final score of a league period (SRS FR-COMP-06, FR-PLAN-07; HLD §4.9): when `days:finalize`
 * makes the last day of a period final, it stores the user's result for that period.
 *
 * It is noon UTC on Tuesday, 17 November 2026. Days up to Saturday the 14th are due (a day is final
 * 48 hours after it ended in Vienna). The week 2026-W45 ran from Monday the 2nd to Sunday the 8th.
 */
final class LeagueScoresTest extends LoggingUseCaseTestCase
{
    private User $anna;
    private int $logs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_COMP_06_the_last_day_of_a_week_stores_the_result_of_the_week(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-02');
        // Five days of 6, a day without anything, and on Sunday 20 logged and never checked in.
        foreach (['02', '03', '04', '05', '06'] as $day) {
            $this->day("2026-11-{$day}", 6, true);
        }
        $this->day('2026-11-08', 20, false);

        $this->finalizeDays()->execute();

        // 30 + 12 (a day without a check-in counts as the baseline) + 20 (or as what was logged, when
        // that is more) = 62 of 84. Stored exactly, to be ranked from now on.
        self::assertEquals(
            [new LeagueResult(
                $this->anna->id, CalendarPeriod::fromKey(LeaguePeriod::Week, '2026-W45'), 120, 620, 840, 0, 5, 7, 'v1', $this->now(), $this->now(),
            )],
            $this->leagueResults->all(),
        );
    }

    public function test_FR_COMP_06_a_period_is_stored_once_and_only_when_its_last_day_is_final(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-02');

        $this->finalizeDays()->execute();
        $this->finalizeDays()->execute();

        // The week to the 15th is not through yet: the 15th is final tomorrow night.
        self::assertSame(['week 2026-W45'], $this->labels());

        $this->clock->advanceSeconds(86_400);
        $this->finalizeDays()->execute();
        self::assertSame(['week 2026-W45', 'week 2026-W46'], $this->labels());
    }

    public function test_FR_COMP_06_somebody_who_joined_in_the_middle_is_rated_over_the_days_since(): void
    {
        // The onboarding was on Wednesday the 4th.
        $this->givenOnboarded($this->anna, '2026-11-04');
        foreach (['04', '05', '06', '07', '08'] as $day) {
            $this->day("2026-11-{$day}", 3, true);
        }

        $this->finalizeDays()->execute();

        // Five days: 15 of 60.
        self::assertEquals(
            [new LeagueResult($this->anna->id, CalendarPeriod::fromKey(LeaguePeriod::Week, '2026-W45'), 120, 150, 600, 0, 5, 5, 'v1', $this->now(), $this->now())],
            $this->leagueResults->all(),
        );
    }

    public function test_FR_PLAN_07_a_period_is_scored_against_the_baseline_of_its_first_counted_day(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-02');
        // 6.0 from Monday the 9th, the first day of the second week. 3.0 from the 16th.
        $this->givenWeeklyBaseline($this->anna, '2026-11-09', 60);
        $this->givenWeeklyBaseline($this->anna, '2026-11-16', 30);
        $this->clock->advanceSeconds(86_400);

        $this->finalizeDays()->execute();

        // Nothing was checked in, so every day counts as the baseline: the first week against 12.0,
        // the second against 6.0.
        self::assertSame(
            [['week 2026-W45', 120, 840, 840], ['week 2026-W46', 60, 420, 420]],
            array_map(
                static fn (LeagueResult $result): array => ["{$result->period->type->value} {$result->period->key}", $result->b0Tenths, $result->actualTenths, $result->expectedTenths],
                $this->leagueResults->all(),
            ),
        );
    }

    public function test_FR_PLAN_07_no_period_is_scored_against_a_baseline_below_one(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-02');
        // The baseline went down to 0.4 on Monday the 9th.
        $this->givenWeeklyBaseline($this->anna, '2026-11-09', 4);
        $this->day('2026-11-09', 2, true);
        $this->clock->advanceSeconds(86_400);

        $this->finalizeDays()->execute();

        // The week from the 9th against 1.0 a day: 2 + 6 days at the baseline = 8 of 7.
        $result = $this->leagueResults->all()[1];
        self::assertSame(['2026-W46', 10, 80, 70], [$result->period->key, $result->b0Tenths, $result->actualTenths, $result->expectedTenths]);
    }

    public function test_FR_COMP_06_the_last_day_of_june_ends_a_month_a_quarter_and_a_half_year(): void
    {
        // On board since the 20th of June. Every day from the 28th of June on is finalized now.
        $this->givenOnboarded($this->anna, '2026-06-20', '2026-06-28');

        $this->finalizeDays()->execute();

        $results = [];
        foreach ($this->leagueResults->all() as $result) {
            $results["{$result->period->type->value} {$result->period->key}"] = [$result->countedDays, $result->expectedTenths];
        }
        // 20 weeks from the one that ended on Sunday the 28th of June to the one that ended on the 8th of November.
        $weeks = array_map(static fn (int $week): string => "week 2026-W{$week}", range(26, 45));
        $others = ['month 2026-06', 'quarter 2026-Q2', 'half_year 2026-H1', 'month 2026-07', 'month 2026-08', 'month 2026-09', 'quarter 2026-Q3', 'month 2026-10'];
        $labels = array_keys($results);
        sort($labels);
        $expected = [...$weeks, ...$others];
        sort($expected);
        self::assertSame($expected, $labels);
        // The periods that had begun before the onboarding count from the 20th of June: 11 days.
        self::assertSame([11, 1320], $results['month 2026-06']);
        self::assertSame([11, 1320], $results['quarter 2026-Q2']);
        self::assertSame([11, 1320], $results['half_year 2026-H1']);
        self::assertSame([7, 840], $results['week 2026-W26']);
        self::assertSame([31, 3720], $results['month 2026-07']);
        self::assertSame([92, 11040], $results['quarter 2026-Q3']);
    }

    public function test_FR_COMP_06_a_user_without_a_day_that_became_final_has_no_result(): void
    {
        // On board since yesterday: nothing is due.
        $this->givenOnboarded($this->anna, '2026-11-16');

        $this->finalizeDays()->execute();

        self::assertSame([], $this->leagueResults->all());
    }

    /**
     * A day of Anna as the app left it: its sessions in one log at ten in the morning, and the
     * check-in if there was one. (Finalization counts the logs, not the stored count.)
     */
    private function day(string $date, int $count, bool $checkedIn): void
    {
        $this->givenLog($this->anna, ++$this->logs, $date, "{$date} 10:00:00", $count);
        $this->givenDay($this->anna, $date, $count, $checkedIn ? "{$date} 20:00:00" : null);
    }

    /**
     * @return list<string> "<type> <key>" of every stored result, in the order they were stored.
     */
    private function labels(): array
    {
        return array_map(static fn (LeagueResult $result): string => "{$result->period->type->value} {$result->period->key}", $this->leagueResults->all());
    }
}
