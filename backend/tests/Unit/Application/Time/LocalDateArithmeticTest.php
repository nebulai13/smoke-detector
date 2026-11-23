<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Time;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Time\LocalDate;

/**
 * Counting with calendar days (HLD §4.6 "Local days"): the next day, the days between two dates,
 * the weekday. A calendar day is not 24 hours, so none of this goes through a clock.
 */
final class LocalDateArithmeticTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function sums(): iterable
    {
        yield 'the next day' => ['2026-11-17', 1, '2026-11-18'];
        yield 'the day before' => ['2026-11-17', -1, '2026-11-16'];
        yield 'no days' => ['2026-11-17', 0, '2026-11-17'];
        yield 'into the next month' => ['2026-11-30', 1, '2026-12-01'];
        yield 'into the next year' => ['2026-12-31', 1, '2027-01-01'];
        yield 'back into the last year' => ['2027-01-01', -1, '2026-12-31'];
        yield 'onto a leap day' => ['2028-02-28', 1, '2028-02-29'];
        yield 'past February without a leap day' => ['2027-02-28', 1, '2027-03-01'];
        yield 'four weeks back' => ['2026-11-23', -28, '2026-10-26'];
        yield 'across the day summer time begins' => ['2027-03-27', 2, '2027-03-29'];
    }

    #[DataProvider('sums')]
    public function test_FR_I18N_02_days_are_added_as_calendar_days(string $date, int $days, string $expected): void
    {
        self::assertSame($expected, LocalDate::fromString($date)->plusDays($days)->value);
    }

    public function test_adding_days_leaves_the_date_itself_as_it_was(): void
    {
        $date = LocalDate::fromString('2026-11-17');

        $date->plusDays(3);

        self::assertSame('2026-11-17', $date->value);
    }

    public function test_FR_I18N_02_one_date_is_before_or_after_another_or_the_same(): void
    {
        $monday = LocalDate::fromString('2026-11-16');
        $tuesday = LocalDate::fromString('2026-11-17');
        $nextYear = LocalDate::fromString('2027-01-02');

        self::assertTrue($monday->isBefore($tuesday));
        self::assertFalse($tuesday->isBefore($monday));
        self::assertFalse($monday->isBefore(LocalDate::fromString('2026-11-16')));
        self::assertTrue($tuesday->isAfter($monday));
        self::assertFalse($monday->isAfter($tuesday));
        self::assertFalse($monday->isAfter(LocalDate::fromString('2026-11-16')));
        // Compared as dates, not as text that happens to sort: the year counts first.
        self::assertTrue($tuesday->isBefore($nextYear));
        self::assertTrue($nextYear->isAfter($tuesday));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function distances(): iterable
    {
        yield 'three days ahead' => ['2026-11-17', '2026-11-20', 3];
        yield 'three days back' => ['2026-11-20', '2026-11-17', -3];
        yield 'the same day' => ['2026-11-17', '2026-11-17', 0];
        yield 'across the end of a year' => ['2026-12-30', '2027-01-02', 3];
        yield 'across a leap day' => ['2028-02-28', '2028-03-01', 2];
        yield 'across the day summer time begins' => ['2027-03-27', '2027-03-29', 2];
        yield 'a whole year' => ['2026-11-17', '2027-11-17', 365];
    }

    #[DataProvider('distances')]
    public function test_FR_I18N_02_the_days_from_one_date_to_another_are_calendar_days(string $from, string $to, int $expected): void
    {
        self::assertSame($expected, LocalDate::fromString($from)->daysUntil(LocalDate::fromString($to)));
    }

    public function test_FR_PLAN_04_a_date_knows_its_weekday_monday_is_1_and_sunday_is_7(): void
    {
        $weekdays = [];
        foreach (['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23'] as $date) {
            $weekdays[] = LocalDate::fromString($date)->isoWeekday();
        }

        self::assertSame([1, 2, 3, 4, 5, 6, 7, 1], $weekdays);
    }
}
