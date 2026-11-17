<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Plan;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Plan\Baseline;

/**
 * The baseline value itself (SRS §1.4): sessions per day with one decimal place, kept in tenths.
 *
 * Every daily limit of FR-PLAN-01 is derived from this value, so a baseline that is not a real
 * number must never be turned into one silently.
 */
final class BaselineTest extends TestCase
{
    public function test_FR_PLAN_01_baseline_keeps_one_decimal_place_exactly(): void
    {
        for ($tenths = 0; $tenths <= 800; $tenths++) {
            $baseline = Baseline::of($tenths / 10.0);

            self::assertSame($tenths, $baseline->tenths);
            self::assertSame($tenths / 10.0, $baseline->toFloat());
            self::assertEquals($baseline, Baseline::fromTenths($tenths));
        }
    }

    #[DataProvider('moreThanOneDecimal')]
    public function test_FR_PLAN_01_baseline_with_more_decimals_is_rounded_half_up_to_one(float $value, int $tenths): void
    {
        self::assertSame($tenths, Baseline::of($value)->tenths);
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function moreThanOneDecimal(): iterable
    {
        yield '7.44 → 7.4' => [7.44, 74];
        yield '7.45 → 7.5' => [7.45, 75];
        yield '7.46 → 7.5' => [7.46, 75];
        yield '0.04 → 0.0' => [0.04, 0];
        yield '0.05 → 0.1' => [0.05, 1];
        yield '79.99 → 80.0' => [79.99, 800];
    }

    public function test_FR_PLAN_01_baseline_of_zero_is_allowed(): void
    {
        // A quitter's baseline reaches 0.0 (SRS FR-PLAN-06).
        self::assertSame(0, Baseline::of(0.0)->tenths);
        self::assertSame(0, Baseline::of(-0.0)->tenths);
        self::assertSame(0, Baseline::fromTenths(0)->tenths);
    }

    #[DataProvider('negativeValues')]
    public function test_FR_PLAN_01_negative_baseline_is_rejected_even_when_it_would_round_to_zero(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A baseline cannot be negative.');

        Baseline::of($value);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function negativeValues(): iterable
    {
        yield '-0.04' => [-0.04];
        yield '-0.05' => [-0.05];
        yield '-1.0' => [-1.0];
        yield 'smallest negative number' => [-PHP_FLOAT_MIN];
        yield 'minus infinity' => [-INF];
    }

    public function test_FR_PLAN_01_negative_tenths_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A baseline cannot be negative.');

        Baseline::fromTenths(-1);
    }

    #[DataProvider('notANumber')]
    public function test_FR_PLAN_01_baseline_that_is_not_a_finite_number_is_rejected(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A baseline must be a finite number that fits into an integer of tenths.');

        Baseline::of($value);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function notANumber(): iterable
    {
        yield 'NAN' => [NAN];
        yield 'INF' => [INF];
        yield '1e30' => [1e30];
        yield '1e18' => [1e18];
        // 2^63 tenths: the first value that is no longer an integer.
        yield '2^63 tenths' => [922_337_203_685_477_580.8];
    }

    public function test_FR_PLAN_01_largest_baseline_that_still_fits_is_accepted(): void
    {
        // The largest float below 2^63 is 2^63 − 1024.
        $tenths = PHP_INT_MAX - 1023;

        self::assertSame($tenths, Baseline::of($tenths / 10)->tenths);
    }
}
