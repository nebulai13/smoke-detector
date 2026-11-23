<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use DateTimeZone;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Domain\Xp\LeaguePeriod;

/**
 * One calendar period of a league (SRS FR-COMP-06, HLD §4.9 "Periods"): an ISO week, a month, a
 * quarter or a half-year, the same dates for everybody. Two periods of the same type and key are
 * equal (==), however they were built.
 */
final readonly class CalendarPeriod
{
    private const string NO_PERIOD = 'There is no such period of this type.';

    private function __construct(
        public LeaguePeriod $type,
        /** E.g. "2026-W47", "2026-11", "2026-Q4", "2026-H2". The year of a week is its ISO year. */
        public string $key,
        private LocalDate $first,
        private LocalDate $last,
    ) {
    }

    /**
     * The period of this type the date lies in.
     */
    public static function containing(LeaguePeriod $type, LocalDate $date): self
    {
        $day = new DateTimeImmutable($date->value, new DateTimeZone('UTC'));
        if ($type === LeaguePeriod::Week) {
            $monday = $date->plusDays(1 - $date->isoWeekday());

            return new self($type, self::key($type, (int) $day->format('o'), (int) $day->format('W')), $monday, $monday->plusDays(6));
        }

        return self::ofNumber($type, (int) $day->format('Y'), self::number((int) $day->format('n'), self::months($type)));
    }

    /**
     * The period a key names.
     *
     * @throws InvalidValue invalid_format when the text is no key of this type; invalid_value when it
     *                      looks like one and there is no such period (week 53 of a year with 52).
     */
    public static function fromKey(LeaguePeriod $type, string $key): self
    {
        if (preg_match(self::pattern($type), $key, $parts) !== 1) {
            throw new InvalidValue(ErrorCode::InvalidFormat, self::NO_PERIOD);
        }
        $year = (int) $parts['year'];
        $number = (int) $parts['number'];
        // A number past the end rolls over into another period, whose key is then another one.
        $period = $type === LeaguePeriod::Week
            ? self::containing($type, LocalDate::fromString((new DateTimeImmutable('@0'))->setISODate($year, $number)->format('Y-m-d')))
            : self::ofNumber($type, $year, $number);
        if ($period->key !== $key) {
            throw new InvalidValue(ErrorCode::InvalidValue, self::NO_PERIOD);
        }

        return $period;
    }

    public function first(): LocalDate
    {
        return $this->first;
    }

    public function last(): LocalDate
    {
        return $this->last;
    }

    /**
     * How many days the period has.
     */
    public function days(): int
    {
        return $this->first->daysUntil($this->last) + 1;
    }

    /**
     * The period of a type other than the week by its number in the year. A number past the end
     * rolls over into another year, and the key is the one of that period.
     */
    private static function ofNumber(LeaguePeriod $type, int $year, int $number): self
    {
        $months = self::months($type);
        $first = (new DateTimeImmutable('@0'))->setDate($year, ($number - 1) * $months + 1, 1);

        return new self(
            $type,
            self::key($type, (int) $first->format('Y'), self::number((int) $first->format('n'), $months)),
            LocalDate::fromString($first->format('Y-m-d')),
            LocalDate::fromString($first->modify("+{$months} months -1 day")->format('Y-m-d')),
        );
    }

    /**
     * The number in the year of the period that holds the month (1 to 12).
     */
    private static function number(int $month, int $months): int
    {
        return intdiv($month - 1, $months) + 1;
    }

    private static function key(LeaguePeriod $type, int $year, int $number): string
    {
        return sprintf('%04d-' . match ($type) {
            LeaguePeriod::Week => 'W%02d',
            LeaguePeriod::Month => '%02d',
            LeaguePeriod::Quarter => 'Q%d',
            LeaguePeriod::HalfYear => 'H%d',
        }, $year, $number);
    }

    private static function pattern(LeaguePeriod $type): string
    {
        return match ($type) {
            LeaguePeriod::Week => '/\A(?<year>[0-9]{4})-W(?<number>[0-9]{2})\z/',
            LeaguePeriod::Month => '/\A(?<year>[0-9]{4})-(?<number>[0-9]{2})\z/',
            LeaguePeriod::Quarter => '/\A(?<year>[0-9]{4})-Q(?<number>[0-9])\z/',
            LeaguePeriod::HalfYear => '/\A(?<year>[0-9]{4})-H(?<number>[0-9])\z/',
        };
    }

    /**
     * How many months a period of a type other than the week has.
     */
    private static function months(LeaguePeriod $type): int
    {
        return match ($type) {
            LeaguePeriod::Month => 1,
            LeaguePeriod::Quarter => 3,
            default => 6,
        };
    }
}
