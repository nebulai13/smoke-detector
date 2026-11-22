<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Time;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Time\LocalDate;

/**
 * A calendar day as the user lives it (HLD §7 "Time zones").
 */
final class LocalDateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function moments(): iterable
    {
        yield 'Berlin is an hour ahead in winter' => ['2026-11-17 22:59:59', 'Europe/Berlin', '2026-11-17'];
        yield 'and past midnight from 23:00 UTC on' => ['2026-11-17 23:00:00', 'Europe/Berlin', '2026-11-18'];
        yield 'two hours ahead in summer' => ['2026-07-01 22:00:00', 'Europe/Berlin', '2026-07-02'];
        yield 'Honolulu is still on the day before' => ['2026-11-17 09:59:59', 'Pacific/Honolulu', '2026-11-16'];
        yield 'Kiritimati is 14 hours ahead' => ['2026-11-17 10:00:00', 'Pacific/Kiritimati', '2026-11-18'];
        yield 'UTC' => ['2026-12-31 23:59:59', 'UTC', '2026-12-31'];
        yield 'the year changes with the day' => ['2026-12-31 23:00:00', 'Europe/Berlin', '2027-01-01'];
    }

    #[DataProvider('moments')]
    public function test_the_day_of_a_moment_depends_on_the_time_zone(string $utc, string $zone, string $expected): void
    {
        $moment = new DateTimeImmutable("{$utc} UTC");

        self::assertSame($expected, LocalDate::of($moment, TimeZone::fromInput($zone))->value);
    }

    public function test_the_zone_the_moment_is_expressed_in_does_not_matter(): void
    {
        $inTokyo = new DateTimeImmutable('2026-11-18 08:30:00 Asia/Tokyo');

        self::assertSame('2026-11-18', LocalDate::of($inTokyo, TimeZone::fromInput('Europe/Berlin'))->value);
        self::assertSame('2026-11-17', LocalDate::of($inTokyo, TimeZone::fromInput('America/New_York'))->value);
    }

    public function test_a_date_is_read_from_its_iso_form(): void
    {
        self::assertSame('2026-11-17', LocalDate::fromString('2026-11-17')->value);
        self::assertSame('2028-02-29', LocalDate::fromString('2028-02-29')->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notDates(): iterable
    {
        yield 'empty' => [''];
        yield 'a day that does not exist' => ['2026-02-30'];
        yield 'no leap year' => ['2026-02-29'];
        yield 'month 13' => ['2026-13-01'];
        yield 'without leading zeros' => ['2026-2-3'];
        yield 'with a time' => ['2026-11-17 12:00:00'];
        yield 'another order' => ['17.11.2026'];
        yield 'a trailing line break' => ["2026-11-17\n"];
        yield 'a space in front' => [' 2026-11-17'];
        yield 'a word' => ['today'];
        yield 'a relative date' => ['+1 day'];
    }

    #[DataProvider('notDates')]
    public function test_anything_but_an_existing_day_as_yyyy_mm_dd_is_rejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A local date must be an existing day as YYYY-MM-DD.');

        LocalDate::fromString($value);
    }

    public function test_two_dates_are_equal_when_they_are_the_same_day(): void
    {
        self::assertTrue(LocalDate::fromString('2026-11-17')->equals(LocalDate::fromString('2026-11-17')));
        self::assertFalse(LocalDate::fromString('2026-11-17')->equals(LocalDate::fromString('2026-11-18')));
    }
}
