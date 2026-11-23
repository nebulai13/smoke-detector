<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use SmokeDetector\Infrastructure\Clock\FrozenClock;

/**
 * The clock for tests: time stands still until the test moves it.
 */
final class FrozenClockTest extends TestCase
{
    public function test_it_is_a_psr_clock(): void
    {
        self::assertInstanceOf(ClockInterface::class, FrozenClock::at('2026-11-17 12:00:00'));
    }

    public function test_time_stands_still(): void
    {
        $clock = FrozenClock::at('2026-11-17 12:00:00');

        self::assertSame('2026-11-17T12:00:00.000000+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
        self::assertEquals($clock->now(), $clock->now());
    }

    public function test_now_is_always_in_utc(): void
    {
        $vienna = new DateTimeImmutable('2026-07-01 14:30:00', new DateTimeZone('Europe/Vienna'));

        $clock = new FrozenClock($vienna);

        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
        self::assertSame('2026-07-01 12:30:00', $clock->now()->format('Y-m-d H:i:s'));
        self::assertSame($vienna->getTimestamp(), $clock->now()->getTimestamp());
    }

    public function test_microseconds_are_kept(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-17 12:00:00.123456', new DateTimeZone('UTC')));

        self::assertSame('123456', $clock->now()->format('u'));
    }

    public function test_advancing_moves_time_forward_by_exact_seconds(): void
    {
        $clock = FrozenClock::at('2026-03-28 23:59:59');

        $clock->advanceSeconds(1);
        self::assertSame('2026-03-29 00:00:00', $clock->now()->format('Y-m-d H:i:s'));

        // UTC has no daylight saving time, so a day is always 86,400 seconds.
        $clock->advanceSeconds(86_400);
        self::assertSame('2026-03-30 00:00:00', $clock->now()->format('Y-m-d H:i:s'));

        $clock->advanceSeconds(0);
        self::assertSame('2026-03-30 00:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function test_time_never_runs_backwards_by_advancing(): void
    {
        $clock = FrozenClock::at('2026-11-17 12:00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A clock cannot be advanced by a negative number of seconds. Use travelTo().');

        $clock->advanceSeconds(-1);
    }

    public function test_travelling_sets_a_new_time_in_utc(): void
    {
        $clock = FrozenClock::at('2026-11-17 12:00:00');

        $clock->travelTo(new DateTimeImmutable('2025-01-01 01:00:00', new DateTimeZone('+01:00')));

        self::assertSame('2025-01-01T00:00:00+00:00', $clock->now()->format('c'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTimes(): iterable
    {
        yield 'date only' => ['2026-11-17'];
        yield 'relative' => ['tomorrow'];
        yield 'with zone' => ['2026-11-17 12:00:00+01:00'];
        yield 'day that does not exist' => ['2026-02-30 12:00:00'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidTimes')]
    public function test_the_start_time_must_be_a_full_utc_date_and_time(string $time): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A frozen clock needs a UTC time as "YYYY-MM-DD HH:MM:SS".');

        FrozenClock::at($time);
    }
}
