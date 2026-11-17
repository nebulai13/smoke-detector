<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Plan;

use InvalidArgumentException;

/**
 * A user's reference number of sessions per day, with one decimal place.
 *
 * Stored as an integer number of tenths so that limits and caps are exact (no float rounding).
 */
final readonly class Baseline
{
    private function __construct(public int $tenths)
    {
    }

    public static function fromTenths(int $tenths): self
    {
        if ($tenths < 0) {
            throw new InvalidArgumentException('A baseline cannot be negative.');
        }

        return new self($tenths);
    }

    public static function of(float $sessionsPerDay): self
    {
        // Checked before rounding: -0.04 would otherwise round to a valid 0.0.
        if ($sessionsPerDay < 0) {
            throw new InvalidArgumentException('A baseline cannot be negative.');
        }

        $tenths = round($sessionsPerDay * 10);
        // NAN, INF and everything from 2^63 on have no integer value, and the cast would turn them
        // into 0 or a negative number without a word. NAN fails every comparison, hence the negation.
        // The comparison is done in floats, where PHP_INT_MAX is exactly 2^63.
        if (!($tenths < PHP_INT_MAX)) {
            throw new InvalidArgumentException(
                'A baseline must be a finite number that fits into an integer of tenths.',
            );
        }

        return self::fromTenths((int) $tenths);
    }

    public function toFloat(): float
    {
        return $this->tenths / 10;
    }
}
