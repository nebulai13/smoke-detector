<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Day;

use LogicException;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\ShowDays;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * GET /days?from&to (HLD §4.6): the days of a range with count, limit, check-in and whether they
 * are final. Only days the user has: from the first day of the plan to today.
 */
final class ShowDaysTest extends LoggingUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_LOG_05_every_day_of_the_range_is_listed_oldest_first_also_one_without_a_log(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14', '2026-11-15');
        $this->givenDay($this->anna, '2026-11-14', 2, '2026-11-14 21:00:00', TestData::dayResult(7, 30));
        $this->givenDay($this->anna, '2026-11-15', 4, '2026-11-15 22:00:00');
        $this->givenDay($this->anna, '2026-11-17', 12);

        $days = $this->days('2026-11-14', '2026-11-17');

        self::assertSame(
            [
                // A final day has the limit it was finalized with, not the one that would follow today.
                ['2026-11-14', 2, 7, 5, true, true],
                ['2026-11-15', 4, 9, 5, true, false],
                ['2026-11-16', 0, 9, 9, false, false],
                ['2026-11-17', 12, 9, 0, false, false],
            ],
            $days,
        );
    }

    public function test_FR_LOG_05_the_range_is_cut_to_the_days_the_user_has(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-15');
        $this->givenDay($this->anna, '2026-11-16', 3);

        $expected = [['2026-11-15', 0, 9, 9, false, false], ['2026-11-16', 3, 9, 6, false, false], ['2026-11-17', 0, 9, 9, false, false]];

        self::assertSame($expected, $this->days('2026-11-01', '2026-12-31'));
        self::assertSame($expected, $this->days('2026-11-15', '2026-11-17'));
        self::assertSame([['2026-11-16', 3, 9, 6, false, false]], $this->days('2026-11-16', '2026-11-16'));
        self::assertSame([], $this->days('2026-11-01', '2026-11-14'));
        self::assertSame([], $this->days('2026-11-18', '2026-11-30'));
    }

    public function test_FR_I18N_02_today_is_the_last_day_and_follows_the_users_clock(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');

        self::assertSame([['2026-11-17', 0, 9, 9, false, false]], $this->days('2026-11-17', '2026-11-18'));

        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));

        self::assertSame(
            [['2026-11-17', 0, 9, 9, false, false], ['2026-11-18', 0, 9, 9, false, false]],
            $this->days('2026-11-17', '2026-11-18'),
        );
    }

    public function test_a_range_may_span_92_days_and_not_more(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');

        // 30 days of November, 31 of December, 31 of January.
        self::assertCount(1, $this->days('2026-11-01', '2027-01-31'));
        self::assertSame(92, ShowDays::MAX_DAYS);

        try {
            $this->days('2026-11-01', '2027-02-01');
            self::fail('Expected a range of 93 days to be refused.');
        } catch (ValidationFailed $rejected) {
            self::assertCount(1, $rejected->errors);
            self::assertSame(['to', ErrorCode::OutOfRange], [$rejected->errors[0]->field, $rejected->errors[0]->code]);
            self::assertSame('The range may span at most 92 days.', $rejected->errors[0]->message);
        }
    }

    public function test_a_plan_without_progress_is_damaged_data_and_not_a_list_of_open_days(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $this->progressRecords->eraseOfUser($this->anna->id);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A plan exists without progress.');

        $this->days('2026-11-17', '2026-11-17');
    }

    public function test_a_range_that_ends_before_it_begins_is_refused(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');

        try {
            $this->days('2026-11-17', '2026-11-16');
            self::fail('Expected the range to be refused.');
        } catch (ValidationFailed $rejected) {
            self::assertCount(1, $rejected->errors);
            self::assertSame(['to', ErrorCode::OutOfRange], [$rejected->errors[0]->field, $rejected->errors[0]->code]);
        }
    }

    public function test_FR_ACC_06_without_a_plan_there_are_no_days(): void
    {
        $this->expectException(PlanRequired::class);

        $this->days('2026-11-17', '2026-11-17');
    }

    /**
     * @return list<array{string, int, int, int, bool, bool}>
     */
    private function days(string $from, string $to): array
    {
        return self::views($this->showDays()->execute($this->anna->id, self::date($from), self::date($to)));
    }
}
