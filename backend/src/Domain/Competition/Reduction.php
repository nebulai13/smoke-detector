<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Competition;

use InvalidArgumentException;

/**
 * Reduction over a competition window: `1 − actual / expected` (SRS §1.4, HLD §5.4).
 * Higher is better. Negative when the user smoked more than the frozen baseline.
 *
 * Kept as the two exact totals instead of a percentage, because duel winners and league ranks are
 * decided by comparing two users' reductions, and a tie must be detected exactly. Two floats that
 * should be equal (5 of 10 and 10 of 20) can differ in the last bit, and two that should differ can
 * collapse into the same double.
 *
 * Guaranteed bound: both totals are at most MAX_TENTHS (about 214.7 million sessions). Inside that
 * bound every comparison and every display value is computed in 64-bit integers without overflow.
 * Larger totals are rejected instead of scored. A realistic maximum is far below it: a baseline of
 * 80.0 over 184 days is 147,200 tenths, and 1,000 sessions on each of those days is 1,840,000 tenths.
 *
 * Two instances are the same reduction when equals() says so. `==` compares the raw totals, so it
 * treats 5 of 10 and 10 of 20 as different.
 */
final readonly class Reduction
{
    /**
     * 2^31 − 1. The product of two such totals is below 2^62 and fits a 64-bit integer, which is
     * what makes compareTo() exact.
     */
    public const int MAX_TENTHS = 2_147_483_647;

    /** More decimals than this mean nothing on a screen, and the limit keeps toPercent() in integers. */
    private const int MAX_DISPLAY_DECIMALS = 4;

    private function __construct(
        /** Sessions that count for the window, in tenths: checked-in counts plus max(B0, logged) per missed day. */
        public int $actualTenths,
        /** B0 × counted days, in tenths. Always positive. */
        public int $expectedTenths,
    ) {
    }

    public static function fromTotals(int $actualTenths, int $expectedTenths): self
    {
        if ($actualTenths < 0) {
            throw new InvalidArgumentException('The actual total cannot be negative.');
        }
        if ($expectedTenths < 1) {
            throw new InvalidArgumentException(
                'The expected total must be positive: without counted days there is no reduction.',
            );
        }
        if ($actualTenths > self::MAX_TENTHS || $expectedTenths > self::MAX_TENTHS) {
            throw new InvalidArgumentException('The totals are too large to be compared exactly.');
        }

        return new self($actualTenths, $expectedTenths);
    }

    /**
     * Numerator of the reduction fraction `savedTenths / expectedTenths`: sessions fewer than the
     * baseline predicted, in tenths. Negative when over baseline.
     */
    public function savedTenths(): int
    {
        return $this->expectedTenths - $this->actualTenths;
    }

    /**
     * @return int 1 when this reduction is higher (better) than the other, -1 when lower, 0 when
     *             exactly equal
     */
    public function compareTo(self $other): int
    {
        // 1 − a/e > 1 − a'/e'  ⇔  a'/e' > a/e  ⇔  a'·e > a·e', because e and e' are positive.
        return $other->actualTenths * $this->expectedTenths <=> $this->actualTenths * $other->expectedTenths;
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * Percentage for display only, rounded half away from zero: 1.25 → 1.3 and -1.25 → -1.3, so a
     * gain and an equally large loss are shown with the same size. Never compare two of these values
     * to decide a winner or a tie. Use compareTo() for that.
     *
     * @param int $decimals 0 to 4
     */
    public function toPercent(int $decimals = 1): float
    {
        if ($decimals < 0 || $decimals > self::MAX_DISPLAY_DECIMALS) {
            throw new InvalidArgumentException(
                'Decimals must be between 0 and ' . self::MAX_DISPLAY_DECIMALS . '.',
            );
        }

        $unitsPerPercent = 1;
        for ($i = 0; $i < $decimals; $i++) {
            $unitsPerPercent *= 10;
        }

        // Rounded in integers on the magnitude, then the sign is put back: that is half away from zero.
        $saved = $this->savedTenths();
        $magnitude = abs($saved) * 100 * $unitsPerPercent;
        $rounded = intdiv(2 * $magnitude + $this->expectedTenths, 2 * $this->expectedTenths);

        // An integer zero has no sign, so a tiny loss is shown as 0.0 and not as -0.0.
        return ($saved < 0 ? -$rounded : $rounded) / $unitsPerPercent;
    }
}
