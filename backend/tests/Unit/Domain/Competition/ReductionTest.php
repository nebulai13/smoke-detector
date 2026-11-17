<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Competition;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Competition\Reduction;

/**
 * The exact reduction fraction `1 − actual / expected` (SRS §1.4, FR-COMP-03, HLD §5.4).
 *
 * Totals are in tenths of a session: `fromTotals(actual, expected)`.
 */
final class ReductionTest extends TestCase
{
    public function test_FR_COMP_03_reduction_is_one_minus_actual_over_expected(): void
    {
        // 1 − 37.8 / 51.8 = 14.0 / 51.8 = 0.27027… → 27.0 %
        $reduction = Reduction::fromTotals(actualTenths: 378, expectedTenths: 518);

        self::assertSame(378, $reduction->actualTenths);
        self::assertSame(518, $reduction->expectedTenths);
        self::assertSame(140, $reduction->savedTenths());
        self::assertSame(27.0, $reduction->toPercent());
    }

    public function test_FR_COMP_03_higher_reduction_compares_as_greater(): void
    {
        $half = Reduction::fromTotals(50, 100);
        $third = Reduction::fromTotals(200, 300);

        self::assertSame(1, $half->compareTo($third));
        self::assertSame(-1, $third->compareTo($half));
        self::assertFalse($half->equals($third));
    }

    public function test_FR_COMP_03_same_fraction_from_different_baselines_is_equal(): void
    {
        // 5 of 10 and 10 of 20 sessions are both exactly 50 %.
        $small = Reduction::fromTotals(50, 100);
        $large = Reduction::fromTotals(100, 200);

        self::assertSame(0, $small->compareTo($large));
        self::assertSame(0, $large->compareTo($small));
        self::assertTrue($small->equals($large));
        self::assertTrue($large->equals($small));
    }

    public function test_FR_COMP_03_fractions_that_do_not_terminate_in_decimal_are_still_equal(): void
    {
        // Both are a reduction of exactly 1/3, which has no exact decimal or binary form.
        $a = Reduction::fromTotals(20, 30);
        $b = Reduction::fromTotals(3_700, 5_550);

        self::assertTrue($a->equals($b));
    }

    public function test_FR_COMP_03_difference_of_one_tenth_of_a_session_is_not_a_tie(): void
    {
        // Baseline 80.0 over 184 days = 14720.0 expected. Both display as 50.0 %.
        $exactlyHalf = Reduction::fromTotals(73_600, 147_200);
        $oneTenthMore = Reduction::fromTotals(73_601, 147_200);

        self::assertSame($exactlyHalf->toPercent(), $oneTenthMore->toPercent());
        self::assertFalse($exactlyHalf->equals($oneTenthMore));
        self::assertSame(1, $exactlyHalf->compareTo($oneTenthMore));
        self::assertSame(-1, $oneTenthMore->compareTo($exactlyHalf));
    }

    public function test_FR_COMP_03_difference_too_small_for_floats_is_still_not_a_tie(): void
    {
        // actual/expected differ by 1 / (2000000001 × 1999999999) ≈ 2.5e-19, below double precision:
        //   1000000000 × 1999999999 = 1999999999000000000
        //    999999999 × 2000000001 = 1999999998999999999
        // so $a smoked the slightly larger share and has the lower reduction.
        $a = Reduction::fromTotals(1_000_000_000, 2_000_000_001);
        $b = Reduction::fromTotals(999_999_999, 1_999_999_999);

        self::assertSame(
            1 - 1_000_000_000 / 2_000_000_001,
            1 - 999_999_999 / 1_999_999_999,
            'Precondition: float arithmetic cannot tell these two apart.',
        );
        self::assertSame(-1, $a->compareTo($b));
        self::assertSame(1, $b->compareTo($a));
        self::assertFalse($a->equals($b));
    }

    public function test_FR_COMP_03_comparison_is_exact_at_the_guaranteed_bound(): void
    {
        $max = Reduction::MAX_TENTHS;
        $full = Reduction::fromTotals($max, $max);
        $almost = Reduction::fromTotals($max - 1, $max);

        self::assertSame(2_147_483_647, $max);
        self::assertSame(1, $almost->compareTo($full));
        self::assertSame(-1, $full->compareTo($almost));
        self::assertTrue($full->equals(Reduction::fromTotals(10, 10)));
    }

    public function test_FR_COMP_03_negative_reductions_are_ordered_like_any_other(): void
    {
        $zero = Reduction::fromTotals(100, 100);
        $slightlyOver = Reduction::fromTotals(110, 100);
        $farOver = Reduction::fromTotals(250, 100);

        self::assertSame(1, $zero->compareTo($slightlyOver));
        self::assertSame(1, $slightlyOver->compareTo($farOver));
        self::assertSame(-1, $farOver->compareTo($zero));
        self::assertSame(-10, $slightlyOver->savedTenths());
        self::assertTrue($slightlyOver->equals(Reduction::fromTotals(814, 740)));
    }

    public function test_FR_COMP_03_zero_and_full_reduction_are_equal_across_baselines(): void
    {
        self::assertTrue(Reduction::fromTotals(10, 10)->equals(Reduction::fromTotals(5_180, 5_180)));
        self::assertTrue(Reduction::fromTotals(0, 10)->equals(Reduction::fromTotals(0, 5_180)));
        self::assertSame(0.0, Reduction::fromTotals(5_180, 5_180)->toPercent());
        self::assertSame(100.0, Reduction::fromTotals(0, 5_180)->toPercent());
    }

    /**
     * @return iterable<string, array{int, int, int, float}>
     */
    public static function displayRounding(): iterable
    {
        // actual tenths, expected tenths, decimals, displayed percent
        yield '27.027 % → 27.0' => [378, 518, 1, 27.0];
        yield '27.027 % → 27.03' => [378, 518, 2, 27.03];
        yield '27.027 % → 27' => [378, 518, 0, 27.0];
        yield '0.05 % rounds up to 0.1' => [1_999, 2_000, 1, 0.1];
        yield '0.04 % rounds down to 0.0' => [2_499, 2_500, 1, 0.0];
        yield '1.25 % rounds up to 1.3' => [1_975, 2_000, 1, 1.3];
        yield '1.249 % rounds down to 1.2' => [98_751, 100_000, 1, 1.2];
        yield '1.35 % rounds up to 1.4' => [1_973, 2_000, 1, 1.4];
        yield '33.333… % → 33.3' => [20, 30, 1, 33.3];
        yield '66.666… % → 66.7' => [10, 30, 1, 66.7];
        yield '99.95 % rounds up to 100.0' => [1, 2_000, 1, 100.0];
        yield '12.5 % → 13 without decimals' => [875, 1_000, 0, 13.0];
        yield '0.5 % → 1 without decimals' => [199, 200, 0, 1.0];
        yield '0.125 % → 0.13 with two decimals' => [799, 800, 2, 0.13];
        yield '-0.05 % rounds away from zero to -0.1' => [2_001, 2_000, 1, -0.1];
        yield '-0.04 % rounds to 0.0' => [2_501, 2_500, 1, 0.0];
        yield '-1.25 % rounds away from zero to -1.3' => [2_025, 2_000, 1, -1.3];
        yield '-1.249 % rounds to -1.2' => [101_249, 100_000, 1, -1.2];
        yield '-12.5 % → -13 without decimals' => [1_125, 1_000, 0, -13.0];
        yield '-0.5 % → -1 without decimals' => [201, 200, 0, -1.0];
        yield '-33.333… % → -33.3' => [40, 30, 1, -33.3];
        yield '-150 % stays exact' => [250, 100, 1, -150.0];
        yield 'four decimals' => [20, 30, 4, 33.3333];
    }

    #[DataProvider('displayRounding')]
    public function test_FR_COMP_03_display_percent_rounds_half_away_from_zero(
        int $actualTenths,
        int $expectedTenths,
        int $decimals,
        float $expected,
    ): void {
        self::assertSame($expected, Reduction::fromTotals($actualTenths, $expectedTenths)->toPercent($decimals));
    }

    public function test_FR_COMP_03_display_percent_defaults_to_one_decimal(): void
    {
        self::assertSame(1.3, Reduction::fromTotals(1_975, 2_000)->toPercent());
    }

    public function test_FR_COMP_03_tiny_negative_reduction_is_displayed_without_a_minus_sign(): void
    {
        // -0.04 % rounds to zero. "-0.0 %" on a leaderboard would look like a loss.
        $percent = Reduction::fromTotals(2_501, 2_500)->toPercent();

        self::assertSame('0.0', sprintf('%.1f', $percent));
    }

    public function test_FR_COMP_03_display_percent_stays_exact_at_the_guaranteed_bound(): void
    {
        $max = Reduction::MAX_TENTHS;

        self::assertSame(100.0, Reduction::fromTotals(0, $max)->toPercent(4));
        // 1 − 2147483647 / 1073741823 = −1.0000000009… → −100.0000 %
        self::assertSame(-100.0, Reduction::fromTotals($max, 1_073_741_823)->toPercent(4));
        // 1 − 2147483647 / 1 = −2147483646 → −214748364600 %
        self::assertSame(-214_748_364_600.0, Reduction::fromTotals($max, 1)->toPercent(4));
    }

    public function test_FR_COMP_03_negative_number_of_decimals_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reduction::fromTotals(50, 100)->toPercent(-1);
    }

    public function test_FR_COMP_03_more_than_four_decimals_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reduction::fromTotals(50, 100)->toPercent(5);
    }

    public function test_FR_COMP_03_rejected_number_of_decimals_names_the_allowed_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Decimals must be between 0 and 4.');

        Reduction::fromTotals(50, 100)->toPercent(5);
    }

    public function test_FR_COMP_03_negative_actual_total_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reduction::fromTotals(-1, 100);
    }

    public function test_FR_COMP_03_expected_total_of_zero_is_rejected(): void
    {
        // 0 / 0 is not a reduction. Zero counted days must be handled before this point.
        $this->expectException(InvalidArgumentException::class);

        Reduction::fromTotals(0, 0);
    }

    public function test_FR_COMP_03_negative_expected_total_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reduction::fromTotals(10, -100);
    }

    public function test_FR_COMP_03_actual_total_above_the_guaranteed_bound_is_rejected(): void
    {
        // Rejected instead of scored, because the comparison could silently overflow into floats.
        $this->expectException(InvalidArgumentException::class);

        Reduction::fromTotals(Reduction::MAX_TENTHS + 1, 100);
    }

    public function test_FR_COMP_03_expected_total_above_the_guaranteed_bound_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reduction::fromTotals(100, Reduction::MAX_TENTHS + 1);
    }

    public function test_NFR_04_comparison_is_consistent_and_repeatable(): void
    {
        $values = [
            Reduction::fromTotals(0, 740),
            Reduction::fromTotals(378, 518),
            Reduction::fromTotals(50, 100),
            Reduction::fromTotals(100, 200),
            Reduction::fromTotals(740, 740),
            Reduction::fromTotals(900, 740),
            Reduction::fromTotals(73_601, 147_200),
        ];

        foreach ($values as $a) {
            self::assertSame(0, $a->compareTo($a));
            self::assertTrue($a->equals($a));
            foreach ($values as $b) {
                self::assertSame($a->compareTo($b), $a->compareTo($b));
                self::assertSame(-$a->compareTo($b), $b->compareTo($a));
                self::assertSame($a->compareTo($b) === 0, $a->equals($b));
                // Transitivity: a ≥ b and b ≥ c means a ≥ c, otherwise a ranking could cycle.
                foreach ($values as $c) {
                    if ($a->compareTo($b) >= 0 && $b->compareTo($c) >= 0) {
                        self::assertGreaterThanOrEqual(0, $a->compareTo($c));
                    }
                }
            }
        }
    }

    public function test_NFR_04_same_totals_give_the_same_reduction_and_display_value(): void
    {
        $first = Reduction::fromTotals(378, 518);
        $second = Reduction::fromTotals(378, 518);

        self::assertEquals($first, $second);
        self::assertSame($first->toPercent(2), $second->toPercent(2));
    }
}
