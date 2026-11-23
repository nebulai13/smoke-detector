<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Database;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/**
 * Reads the columns of one result row with their types, and converts times between PHP and the
 * DATETIME columns, which hold UTC to the second.
 *
 * A column that is not what the schema promises is a bug or a damaged row. It stops the request
 * instead of travelling on as a wrong value.
 */
final readonly class Row
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * @param array<string, mixed> $columns
     */
    public function __construct(private array $columns)
    {
    }

    public function string(string $column): string
    {
        $value = $this->columns[$column] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException("Column \"{$column}\" is not a string.");
        }

        return $value;
    }

    public function nullableString(string $column): ?string
    {
        return ($this->columns[$column] ?? null) === null ? null : $this->string($column);
    }

    public function int(string $column): int
    {
        $value = $this->columns[$column] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException("Column \"{$column}\" is not an integer.");
        }

        return $value;
    }

    public function nullableInt(string $column): ?int
    {
        return ($this->columns[$column] ?? null) === null ? null : $this->int($column);
    }

    /**
     * A DOUBLE column. A whole number may arrive as an integer.
     */
    public function nullableFloat(string $column): ?float
    {
        $value = $this->columns[$column] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_float($value) && !is_int($value)) {
            throw new UnexpectedValueException("Column \"{$column}\" is not a number.");
        }

        return (float) $value;
    }

    /**
     * A DECIMAL(n,1) column that is not negative, as a whole number of tenths: "7.4" is 74. Read from
     * the text the database sends, so no float is involved.
     */
    public function tenths(string $column): int
    {
        $text = $this->string($column);
        if (preg_match('/\A(\d{1,17})\.(\d)\z/', $text, $match) !== 1) {
            throw new UnexpectedValueException("Column \"{$column}\" is not a number with one decimal place.");
        }

        return (int) $match[1] * 10 + (int) $match[2];
    }

    public function time(string $column): DateTimeImmutable
    {
        $text = $this->string($column);
        $time = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $text, new DateTimeZone('UTC'));
        if ($time === false || $time->format(self::FORMAT) !== $text) {
            throw new UnexpectedValueException("Column \"{$column}\" is not a date and time.");
        }

        return $time;
    }

    public function nullableTime(string $column): ?DateTimeImmutable
    {
        return ($this->columns[$column] ?? null) === null ? null : $this->time($column);
    }

    /**
     * A time as a DATETIME column takes it: in UTC, whatever zone the object is in. Fractions of a
     * second are cut off.
     */
    public static function sqlTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function nullableSqlTime(?DateTimeImmutable $time): ?string
    {
        return $time === null ? null : self::sqlTime($time);
    }
}
