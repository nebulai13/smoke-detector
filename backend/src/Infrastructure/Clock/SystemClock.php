<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The real time, always in UTC.
 *
 * Every timestamp is stored in UTC, and a user's local day is derived from the user's own time zone,
 * never from the PHP default time zone of the server (CET on the hosting server).
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
