<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Clock;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use SmokeDetector\Infrastructure\Clock\SystemClock;

/**
 * The production clock: the real time, always in UTC (all timestamps are stored in UTC).
 */
final class SystemClockTest extends TestCase
{
    public function test_it_is_a_psr_clock(): void
    {
        self::assertInstanceOf(ClockInterface::class, new SystemClock());
    }

    public function test_now_is_the_current_time(): void
    {
        $before = time();
        $now = (new SystemClock())->now();
        $after = time();

        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual($after, $now->getTimestamp());
    }

    public function test_now_is_in_utc_whatever_the_php_default_time_zone_is(): void
    {
        $default = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
        try {
            $now = (new SystemClock())->now();
        } finally {
            date_default_timezone_set($default);
        }

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertSame(0, $now->getOffset());
    }
}
