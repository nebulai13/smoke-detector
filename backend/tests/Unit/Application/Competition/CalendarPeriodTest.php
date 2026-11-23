<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Competition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Competition\CalendarPeriod;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Domain\Xp\LeaguePeriod;

/**
 * The calendar periods of the leagues (SRS FR-COMP-06, HLD §4.9): ISO week, month, quarter and
 * half-year, each with a key, a first and a last day.
 */
final class CalendarPeriodTest extends TestCase
{
    /**
     * @return iterable<string, array{LeaguePeriod, string, string, string, string, int}> Type, a date in the
     *                                                                                     period, key, first
     *                                                                                     day, last day, days.
     */
    public static function periods(): iterable
    {
        yield 'a week in the middle of the year' => [LeaguePeriod::Week, '2026-11-17', '2026-W47', '2026-11-16', '2026-11-22', 7];
        yield 'a Monday is the first day of its week' => [LeaguePeriod::Week, '2026-11-16', '2026-W47', '2026-11-16', '2026-11-22', 7];
        yield 'a Sunday is the last day of its week' => [LeaguePeriod::Week, '2026-11-22', '2026-W47', '2026-11-16', '2026-11-22', 7];
        // 2026 has 53 ISO weeks: its last one reaches into January.
        yield 'New Year in the last week of the old ISO year' => [LeaguePeriod::Week, '2027-01-01', '2026-W53', '2026-12-28', '2027-01-03', 7];
        yield 'the first Monday of January after that' => [LeaguePeriod::Week, '2027-01-04', '2027-W01', '2027-01-04', '2027-01-10', 7];
        yield 'the end of December in the first week of the new ISO year' => [LeaguePeriod::Week, '2024-12-30', '2025-W01', '2024-12-30', '2025-01-05', 7];
        yield 'a Sunday in January in week 53' => [LeaguePeriod::Week, '2021-01-03', '2020-W53', '2020-12-28', '2021-01-03', 7];
        yield 'week 1 with a single digit' => [LeaguePeriod::Week, '2026-01-01', '2026-W01', '2025-12-29', '2026-01-04', 7];

        yield 'a month of 30 days' => [LeaguePeriod::Month, '2026-11-17', '2026-11', '2026-11-01', '2026-11-30', 30];
        yield 'the first day of a month' => [LeaguePeriod::Month, '2026-12-01', '2026-12', '2026-12-01', '2026-12-31', 31];
        yield 'the last day of a month' => [LeaguePeriod::Month, '2026-01-31', '2026-01', '2026-01-01', '2026-01-31', 31];
        yield 'February' => [LeaguePeriod::Month, '2026-02-10', '2026-02', '2026-02-01', '2026-02-28', 28];
        yield 'February in a leap year' => [LeaguePeriod::Month, '2028-02-29', '2028-02', '2028-02-01', '2028-02-29', 29];

        yield 'the first quarter' => [LeaguePeriod::Quarter, '2026-03-31', '2026-Q1', '2026-01-01', '2026-03-31', 90];
        yield 'the first day of the second quarter' => [LeaguePeriod::Quarter, '2026-04-01', '2026-Q2', '2026-04-01', '2026-06-30', 91];
        yield 'the third quarter' => [LeaguePeriod::Quarter, '2026-09-30', '2026-Q3', '2026-07-01', '2026-09-30', 92];
        yield 'the fourth quarter' => [LeaguePeriod::Quarter, '2026-11-17', '2026-Q4', '2026-10-01', '2026-12-31', 92];
        yield 'the first quarter of a leap year' => [LeaguePeriod::Quarter, '2028-01-01', '2028-Q1', '2028-01-01', '2028-03-31', 91];

        yield 'the last day of the first half' => [LeaguePeriod::HalfYear, '2026-06-30', '2026-H1', '2026-01-01', '2026-06-30', 181];
        yield 'the first day of the second half' => [LeaguePeriod::HalfYear, '2026-07-01', '2026-H2', '2026-07-01', '2026-12-31', 184];
        yield 'the second half' => [LeaguePeriod::HalfYear, '2026-11-17', '2026-H2', '2026-07-01', '2026-12-31', 184];
        yield 'the first half of a leap year' => [LeaguePeriod::HalfYear, '2028-02-29', '2028-H1', '2028-01-01', '2028-06-30', 182];
    }

    #[DataProvider('periods')]
    public function test_FR_COMP_06_a_date_lies_in_one_period_of_each_type(LeaguePeriod $type, string $date, string $key, string $first, string $last, int $days): void
    {
        $period = CalendarPeriod::containing($type, LocalDate::fromString($date));

        self::assertSame([$type, $key, $first, $last, $days], [$period->type, $period->key, $period->first()->value, $period->last()->value, $period->days()]);
    }

    #[DataProvider('periods')]
    public function test_FR_COMP_06_a_key_names_the_same_period(LeaguePeriod $type, string $date, string $key, string $first, string $last, int $days): void
    {
        $period = CalendarPeriod::fromKey($type, $key);

        self::assertEquals(CalendarPeriod::containing($type, LocalDate::fromString($date)), $period);
        self::assertSame([$first, $last, $days], [$period->first()->value, $period->last()->value, $period->days()]);
    }

    public function test_FR_COMP_06_every_day_of_three_years_lies_in_the_period_that_holds_it(): void
    {
        // Day by day across two changes of the year, a leap day and a year with 53 weeks.
        $date = LocalDate::fromString('2026-01-01');
        $previous = [];
        for ($i = 0; $i < 1096; $i++) {
            foreach (LeaguePeriod::cases() as $type) {
                $period = CalendarPeriod::containing($type, $date);
                self::assertFalse($period->first()->isAfter($date), "{$type->value} {$date->value}");
                self::assertFalse($period->last()->isBefore($date), "{$type->value} {$date->value}");
                self::assertSame($period->first()->daysUntil($period->last()) + 1, $period->days());
                // A new period begins the day after the last one ended: no gap and no overlap.
                $before = $previous[$type->value] ?? null;
                if ($before !== null && $before->key !== $period->key) {
                    self::assertSame($before->last()->plusDays(1)->value, $period->first()->value);
                    self::assertSame($date->value, $period->first()->value);
                }
                $previous[$type->value] = $period;
            }
            $date = $date->plusDays(1);
        }
        self::assertSame('2029-01-01', $date->value);
    }

    public function test_FR_COMP_06_a_week_starts_on_a_monday(): void
    {
        foreach (['2026-11-17', '2027-01-01', '2028-02-29'] as $date) {
            $week = CalendarPeriod::containing(LeaguePeriod::Week, LocalDate::fromString($date));
            self::assertSame([1, 7], [$week->first()->isoWeekday(), $week->last()->isoWeekday()]);
        }
    }

    /**
     * @return iterable<string, array{LeaguePeriod, string, ErrorCode}>
     */
    public static function noKeys(): iterable
    {
        // What does not look like a key of that type.
        foreach ([
            'the key of a month' => [LeaguePeriod::Week, '2026-11'],
            'a week with one digit' => [LeaguePeriod::Week, '2026-W1'],
            'a small w' => [LeaguePeriod::Week, '2026-w47'],
            'a year with two digits' => [LeaguePeriod::Week, '26-W47'],
            'the key of a week' => [LeaguePeriod::Month, '2026-W47'],
            'a month with one digit' => [LeaguePeriod::Month, '2026-1'],
            'a date' => [LeaguePeriod::Month, '2026-11-17'],
            'the key of a half-year' => [LeaguePeriod::Quarter, '2026-H1'],
            'a quarter in words' => [LeaguePeriod::Quarter, '2026-4'],
            'the key of a quarter' => [LeaguePeriod::HalfYear, '2026-Q1'],
            'nothing' => [LeaguePeriod::HalfYear, ''],
            'the word for now' => [LeaguePeriod::Week, 'current'],
            'a line break behind' => [LeaguePeriod::Month, "2026-11\n"],
        ] as $name => [$type, $key]) {
            yield $name => [$type, $key, ErrorCode::InvalidFormat];
        }
        // What looks like one and does not exist.
        foreach ([
            'week 0' => [LeaguePeriod::Week, '2026-W00'],
            'week 54' => [LeaguePeriod::Week, '2026-W54'],
            'week 53 of a year with 52 weeks' => [LeaguePeriod::Week, '2025-W53'],
            'month 0' => [LeaguePeriod::Month, '2026-00'],
            'month 13' => [LeaguePeriod::Month, '2026-13'],
            'quarter 0' => [LeaguePeriod::Quarter, '2026-Q0'],
            'quarter 5' => [LeaguePeriod::Quarter, '2026-Q5'],
            'half 0' => [LeaguePeriod::HalfYear, '2026-H0'],
            'half 3' => [LeaguePeriod::HalfYear, '2026-H3'],
        ] as $name => [$type, $key]) {
            yield $name => [$type, $key, ErrorCode::InvalidValue];
        }
    }

    #[DataProvider('noKeys')]
    public function test_FR_COMP_06_anything_else_is_no_period(LeaguePeriod $type, string $key, ErrorCode $code): void
    {
        try {
            CalendarPeriod::fromKey($type, $key);
            self::fail('Should have been refused.');
        } catch (InvalidValue $refused) {
            self::assertSame($code, $refused->errorCode);
            // The message does not repeat what was sent.
            self::assertSame('There is no such period of this type.', $refused->getMessage());
        }
    }

    public function test_FR_COMP_06_week_53_exists_in_a_year_that_has_it(): void
    {
        self::assertSame('2026-12-28', CalendarPeriod::fromKey(LeaguePeriod::Week, '2026-W53')->first()->value);
        self::assertSame('2020-12-28', CalendarPeriod::fromKey(LeaguePeriod::Week, '2020-W53')->first()->value);
        self::assertSame('2025-12-22', CalendarPeriod::fromKey(LeaguePeriod::Week, '2025-W52')->first()->value);
    }
}
