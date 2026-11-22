<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Time;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SmokeDetector\Application\Account\TimeZone;

/**
 * A calendar day as a user lives it, without a time and without a zone (HLD §7 "Time zones").
 *
 * Days of the game are local days: the same moment is Tuesday for one user and Wednesday for
 * another. So a day is never taken from a clock directly, only through of() with the user's zone.
 */
final readonly class LocalDate
{
    private const string FORMAT = 'Y-m-d';

    private function __construct(
        /** As "YYYY-MM-DD", which is also how a DATE column takes and returns it. */
        public string $value,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $date = DateTimeImmutable::createFromFormat(self::FORMAT, $value, new DateTimeZone('UTC'));
        // createFromFormat() rolls "02-30" over into March and accepts "2026-2-3", hence the round trip.
        if ($date === false || $date->format(self::FORMAT) !== $value) {
            throw new InvalidArgumentException('A local date must be an existing day as YYYY-MM-DD.');
        }

        return new self($value);
    }

    /**
     * The day it is for a user in the given zone at that moment.
     */
    public static function of(DateTimeImmutable $moment, TimeZone $zone): self
    {
        return new self($moment->setTimezone(new DateTimeZone($zone->name))->format(self::FORMAT));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The date $days calendar days later (earlier for a negative number).
     *
     * Counted on a calendar in UTC, which has no daylight saving: a calendar day is not 24 hours
     * of some user's clock.
     */
    public function plusDays(int $days): self
    {
        return new self($this->midnightUtc()->modify(sprintf('%+d days', $days))->format(self::FORMAT));
    }

    public function isBefore(self $other): bool
    {
        return $this->midnightUtc() < $other->midnightUtc();
    }

    public function isAfter(self $other): bool
    {
        return $this->midnightUtc() > $other->midnightUtc();
    }

    /**
     * How many calendar days $other is after this date: negative when it is before, 0 for the same day.
     */
    public function daysUntil(self $other): int
    {
        return (int) $this->midnightUtc()->diff($other->midnightUtc())->format('%r%a');
    }

    /**
     * The weekday as ISO 8601 numbers it: 1 is Monday, 7 is Sunday.
     */
    public function isoWeekday(): int
    {
        return (int) $this->midnightUtc()->format('N');
    }

    /**
     * The date as an instant on a clock without daylight saving, so that calendar arithmetic on it
     * is exact. Only for calculating: the instant means nothing to a user.
     */
    private function midnightUtc(): DateTimeImmutable
    {
        // A date without a time is its midnight.
        return new DateTimeImmutable($this->value, new DateTimeZone('UTC'));
    }
}
