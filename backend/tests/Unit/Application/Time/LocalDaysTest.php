<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Time;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Time\LocalDays;
use SmokeDetector\Application\Time\ZoneChange;
use SmokeDetector\Tests\Support\TestData;

/**
 * The days of one user (HLD §4.6 "Local days", FR-I18N-02): which local date an instant belongs
 * to, and when a date starts and ends, from the history of the user's time zones.
 *
 * Day D starts at 00:00 of D in the zone that is valid on D and ends where D+1 starts. An instant
 * belongs to the latest day that has started.
 */
final class LocalDaysTest extends TestCase
{
    public function test_FR_I18N_02_a_day_runs_from_local_midnight_to_local_midnight(): void
    {
        $days = self::days(['2026-11-01' => 'Europe/Berlin']);

        // Berlin is one hour ahead of UTC in winter.
        self::assertSame('2026-11-16 23:00:00', self::utc($days->startOf(self::date('2026-11-17'))));
        self::assertSame('2026-11-17 23:00:00', self::utc($days->endOf(self::date('2026-11-17'))));
        self::assertSame('2026-11-17', $days->dateOf(TestData::time('2026-11-16 23:00:00'))->value);
        self::assertSame('2026-11-17', $days->dateOf(TestData::time('2026-11-17 22:59:59'))->value);
        self::assertSame('2026-11-18', $days->dateOf(TestData::time('2026-11-17 23:00:00'))->value);
        self::assertSame('2026-11-16', $days->dateOf(TestData::time('2026-11-16 22:59:59'))->value);
    }

    public function test_FR_I18N_02_start_and_end_are_given_in_utc(): void
    {
        $days = self::days(['2026-11-01' => 'Europe/Berlin']);

        self::assertSame('UTC', $days->startOf(self::date('2026-11-17'))->getTimezone()->getName());
        self::assertSame('UTC', $days->endOf(self::date('2026-11-17'))->getTimezone()->getName());
    }

    public function test_FR_I18N_02_the_instant_may_come_in_any_zone(): void
    {
        $days = self::days(['2026-11-01' => 'Europe/Berlin']);
        // 08:30 on the 18th in Tokyo is 00:30 on the 18th in Berlin.
        $inTokyo = new DateTimeImmutable('2026-11-18 08:30:00', new DateTimeZone('Asia/Tokyo'));

        self::assertSame('2026-11-18', $days->dateOf($inTokyo)->value);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function daysOfUnusualLength(): iterable
    {
        yield 'the day summer time begins has 23 hours' => ['Europe/Berlin', '2027-03-28', '2027-03-27 23:00:00', '2027-03-28 22:00:00'];
        yield 'the day summer time ends has 25 hours' => ['Europe/Berlin', '2026-10-25', '2026-10-24 22:00:00', '2026-10-25 23:00:00'];
        // In Havana the clock jumps from 00:00 to 01:00: the day begins with its first instant.
        yield 'a day without a midnight begins when the clock jumps' => ['America/Havana', '2026-03-08', '2026-03-08 05:00:00', '2026-03-09 04:00:00'];
        yield 'a zone that is half an hour off' => ['Asia/Kolkata', '2026-11-17', '2026-11-16 18:30:00', '2026-11-17 18:30:00'];
        yield 'the zone furthest ahead' => ['Pacific/Kiritimati', '2026-11-17', '2026-11-16 10:00:00', '2026-11-17 10:00:00'];
        yield 'the zone furthest behind' => ['Etc/GMT+12', '2026-11-17', '2026-11-17 12:00:00', '2026-11-18 12:00:00'];
    }

    #[DataProvider('daysOfUnusualLength')]
    public function test_FR_I18N_02_a_day_follows_the_clock_of_its_zone(string $zone, string $date, string $start, string $end): void
    {
        $days = self::days(['2026-01-01' => $zone]);

        self::assertSame($start, self::utc($days->startOf(self::date($date))));
        self::assertSame($end, self::utc($days->endOf(self::date($date))));
        self::assertSame($date, $days->dateOf(TestData::time($start))->value);
        self::assertSame($date, $days->dateOf(TestData::time($end)->modify('-1 second'))->value);
        self::assertSame(self::date($date)->plusDays(1)->value, $days->dateOf(TestData::time($end))->value);
        self::assertSame(self::date($date)->plusDays(-1)->value, $days->dateOf(TestData::time($start)->modify('-1 second'))->value);
    }

    public function test_FR_I18N_02_the_zone_of_a_date_is_the_newest_entry_that_is_valid_on_it(): void
    {
        $days = self::days(['2026-11-01' => 'Europe/Berlin', '2026-11-20' => 'America/New_York', '2026-12-05' => 'Asia/Tokyo']);

        $zones = [];
        foreach (['2026-11-01', '2026-11-19', '2026-11-20', '2026-12-04', '2026-12-05', '2027-06-30'] as $date) {
            $zones[$date] = $days->zoneOn(self::date($date))->name;
        }

        self::assertSame(
            [
                '2026-11-01' => 'Europe/Berlin',
                '2026-11-19' => 'Europe/Berlin',
                '2026-11-20' => 'America/New_York',
                '2026-12-04' => 'America/New_York',
                '2026-12-05' => 'Asia/Tokyo',
                '2027-06-30' => 'Asia/Tokyo',
            ],
            $zones,
        );
    }

    public function test_FR_I18N_02_a_date_before_the_first_entry_has_the_zone_of_the_first_entry(): void
    {
        $days = self::days(['2026-11-10' => 'Europe/Berlin', '2026-11-20' => 'America/New_York']);

        self::assertSame('Europe/Berlin', $days->zoneOn(self::date('2026-11-09'))->name);
        self::assertSame('Europe/Berlin', $days->zoneOn(self::date('2020-01-01'))->name);
        self::assertSame('2026-11-01', $days->dateOf(TestData::time('2026-11-01 12:00:00'))->value);
    }

    public function test_FR_I18N_02_the_entries_may_come_in_any_order(): void
    {
        $days = new LocalDays([
            new ZoneChange(self::date('2026-12-05'), TimeZone::fromInput('Asia/Tokyo')),
            new ZoneChange(self::date('2026-11-01'), TimeZone::fromInput('Europe/Berlin')),
            new ZoneChange(self::date('2026-11-20'), TimeZone::fromInput('America/New_York')),
        ]);

        self::assertSame('Europe/Berlin', $days->zoneOn(self::date('2026-11-19'))->name);
        self::assertSame('America/New_York', $days->zoneOn(self::date('2026-11-20'))->name);
        self::assertSame('Asia/Tokyo', $days->zoneOn(self::date('2026-12-05'))->name);
    }

    public function test_FR_I18N_02_moving_west_makes_the_last_day_in_the_old_zone_longer(): void
    {
        // Berlin until the 19th, New York (five hours behind UTC) from the 20th.
        $days = self::days(['2026-11-01' => 'Europe/Berlin', '2026-11-20' => 'America/New_York']);

        self::assertSame('2026-11-18 23:00:00', self::utc($days->startOf(self::date('2026-11-19'))));
        // The 19th ends when the 20th begins in New York: 30 hours after it began.
        self::assertSame('2026-11-20 05:00:00', self::utc($days->endOf(self::date('2026-11-19'))));
        self::assertSame('2026-11-20 05:00:00', self::utc($days->startOf(self::date('2026-11-20'))));
        // 23:30 UTC is already the 20th in Berlin: the day keeps running all the same.
        self::assertSame('2026-11-19', $days->dateOf(TestData::time('2026-11-19 23:30:00'))->value);
        self::assertSame('2026-11-19', $days->dateOf(TestData::time('2026-11-20 04:59:59'))->value);
        self::assertSame('2026-11-20', $days->dateOf(TestData::time('2026-11-20 05:00:00'))->value);
        self::assertSame('2026-11-21 05:00:00', self::utc($days->endOf(self::date('2026-11-20'))));
    }

    public function test_FR_I18N_02_moving_east_makes_the_last_day_in_the_old_zone_shorter(): void
    {
        $days = self::days(['2026-11-01' => 'America/New_York', '2026-11-20' => 'Europe/Berlin']);

        self::assertSame('2026-11-19 05:00:00', self::utc($days->startOf(self::date('2026-11-19'))));
        // The 20th begins in Berlin 18 hours later.
        self::assertSame('2026-11-19 23:00:00', self::utc($days->endOf(self::date('2026-11-19'))));
        self::assertSame('2026-11-19', $days->dateOf(TestData::time('2026-11-19 22:59:59'))->value);
        self::assertSame('2026-11-20', $days->dateOf(TestData::time('2026-11-19 23:00:00'))->value);
        self::assertSame('2026-11-18', $days->dateOf(TestData::time('2026-11-19 04:59:59'))->value);
    }

    public function test_FR_I18N_02_a_move_across_the_date_line_to_the_west_gives_one_long_day(): void
    {
        // Kiritimati is 14 hours ahead of UTC, Pago Pago 11 hours behind.
        $days = self::days(['2026-11-01' => 'Pacific/Kiritimati', '2026-11-20' => 'Pacific/Pago_Pago']);

        self::assertSame('2026-11-18 10:00:00', self::utc($days->startOf(self::date('2026-11-19'))));
        self::assertSame('2026-11-20 11:00:00', self::utc($days->endOf(self::date('2026-11-19'))));
        // At 10:30 UTC on the 19th it is the 20th in the old zone and the 18th in the new one.
        // It is the 19th: that day has started and the 20th has not.
        self::assertSame('2026-11-19', $days->dateOf(TestData::time('2026-11-19 10:30:00'))->value);
        self::assertSame('2026-11-19', $days->dateOf(TestData::time('2026-11-18 10:00:00'))->value);
        self::assertSame('2026-11-19', $days->dateOf(TestData::time('2026-11-20 10:59:59'))->value);
        self::assertSame('2026-11-20', $days->dateOf(TestData::time('2026-11-20 11:00:00'))->value);
        self::assertSame('2026-11-18', $days->dateOf(TestData::time('2026-11-18 09:59:59'))->value);
    }

    public function test_FR_I18N_02_a_move_across_the_date_line_to_the_east_can_leave_a_day_empty(): void
    {
        $days = self::days(['2026-11-01' => 'Pacific/Pago_Pago', '2026-11-20' => 'Pacific/Kiritimati']);

        // The 19th would begin at 11:00 UTC, but the 20th begins at 10:00 UTC already.
        self::assertSame('2026-11-19 11:00:00', self::utc($days->startOf(self::date('2026-11-19'))));
        self::assertSame('2026-11-19 10:00:00', self::utc($days->endOf(self::date('2026-11-19'))));
        // No instant belongs to the 19th: the latest day that has started wins.
        self::assertSame('2026-11-18', $days->dateOf(TestData::time('2026-11-19 09:59:59'))->value);
        self::assertSame('2026-11-20', $days->dateOf(TestData::time('2026-11-19 10:00:00'))->value);
        self::assertSame('2026-11-20', $days->dateOf(TestData::time('2026-11-19 10:30:00'))->value);
        self::assertSame('2026-11-20', $days->dateOf(TestData::time('2026-11-19 11:00:00'))->value);
        self::assertSame('2026-11-21', $days->dateOf(TestData::time('2026-11-20 10:00:00'))->value);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function histories(): iterable
    {
        yield 'one zone' => [['2026-11-01' => 'Europe/Berlin']];
        yield 'west, then east' => [['2026-11-01' => 'Europe/Berlin', '2026-11-18' => 'America/Los_Angeles', '2026-11-22' => 'Asia/Tokyo']];
        yield 'back and forth across the date line' => [[
            '2026-11-01' => 'Pacific/Kiritimati', '2026-11-17' => 'Pacific/Pago_Pago', '2026-11-19' => 'Pacific/Kiritimati', '2026-11-21' => 'Etc/GMT+12',
        ]];
        yield 'a change every day' => [[
            '2026-11-16' => 'Asia/Kolkata', '2026-11-17' => 'America/St_Johns', '2026-11-18' => 'Australia/Eucla', '2026-11-19' => 'Pacific/Chatham',
            '2026-11-20' => 'America/Havana', '2026-11-21' => 'Europe/Berlin',
        ]];
    }

    /**
     * @param array<string, string> $history
     */
    #[DataProvider('histories')]
    public function test_FR_I18N_02_every_instant_has_exactly_one_day_and_days_never_go_backwards(array $history): void
    {
        $days = self::days($history);
        $instant = TestData::time('2026-11-14 00:00:00');
        $last = TestData::time('2026-11-25 00:00:00');
        $previous = null;

        // Every 37 minutes: over eleven days that meets every hour and minute of a day.
        while ($instant <= $last) {
            $date = $days->dateOf($instant);
            $at = self::utc($instant);

            self::assertLessThanOrEqual($instant, $days->startOf($date), "The day of {$at} has started.");
            self::assertGreaterThan($instant, $days->startOf($date->plusDays(1)), "The day after the day of {$at} has not started.");
            if ($previous !== null) {
                self::assertFalse($date->isBefore($previous), "At {$at} the date went back.");
            }

            $previous = $date;
            $instant = $instant->modify('+37 minutes');
        }
    }

    public function test_a_history_without_an_entry_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LocalDays([]);
    }

    public function test_two_entries_for_one_date_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LocalDays([
            new ZoneChange(self::date('2026-11-01'), TimeZone::fromInput('Europe/Berlin')),
            new ZoneChange(self::date('2026-11-01'), TimeZone::fromInput('Asia/Tokyo')),
        ]);
    }

    /**
     * @param array<string, string> $history Valid-from date => zone name.
     */
    private static function days(array $history): LocalDays
    {
        $changes = [];
        foreach ($history as $validFrom => $zone) {
            $changes[] = new ZoneChange(self::date($validFrom), TimeZone::fromInput($zone));
        }

        return new LocalDays($changes);
    }

    private static function date(string $value): LocalDate
    {
        return LocalDate::fromString($value);
    }

    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
