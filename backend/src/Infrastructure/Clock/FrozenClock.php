<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * A clock that stands still until it is told to move: for tests, and for runs with a given time
 * (HLD §9 "Time control").
 *
 * It is the one deliberately mutable object here: a test hands the same instance to the code under
 * test and then moves it, to let a token expire or a rate limit window end without waiting.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable $now)
    {
        $this->now = self::inUtc($now);
    }

    /**
     * @param string $utc The start time in UTC as "YYYY-MM-DD HH:MM:SS".
     */
    public static function at(string $utc): self
    {
        $now = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        // createFromFormat() rolls "02-30" over into March instead of failing, hence the round trip.
        if ($now === false || $now->format('Y-m-d H:i:s') !== $utc) {
            throw new InvalidArgumentException('A frozen clock needs a UTC time as "YYYY-MM-DD HH:MM:SS".');
        }

        return new self($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function travelTo(DateTimeImmutable $now): void
    {
        $this->now = self::inUtc($now);
    }

    public function advanceSeconds(int $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException(
                'A clock cannot be advanced by a negative number of seconds. Use travelTo().',
            );
        }

        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    private static function inUtc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
