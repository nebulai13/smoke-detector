<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Competition;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Competition\CompetitionScore;
use SmokeDetector\Domain\Competition\Reduction;

/**
 * The result of scoring one participant: the reduction plus the FR-COMP-03 tie-breaker inputs.
 */
final class CompetitionScoreTest extends TestCase
{
    public function test_FR_COMP_03_score_carries_the_reduction_and_the_tie_breaker_inputs(): void
    {
        $reduction = Reduction::fromTotals(378, 518);

        $score = new CompetitionScore($reduction, smokeFreeDays: 1, checkIns: 5, countedDays: 7);

        self::assertSame($reduction, $score->reduction);
        self::assertSame(1, $score->smokeFreeDays);
        self::assertSame(5, $score->checkIns);
        self::assertSame(7, $score->countedDays);
    }

    public function test_FR_COMP_03_score_over_a_single_counted_day_is_allowed(): void
    {
        $score = new CompetitionScore(Reduction::fromTotals(0, 74), smokeFreeDays: 1, checkIns: 1, countedDays: 1);

        self::assertSame(1, $score->countedDays);
        self::assertSame(1, $score->checkIns);
        self::assertSame(1, $score->smokeFreeDays);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function impossibleCounts(): iterable
    {
        // smoke-free days, check-ins, counted days
        yield 'no counted days' => [0, 0, 0];
        yield 'negative counted days' => [0, 0, -1];
        yield 'negative check-ins' => [0, -1, 7];
        yield 'negative smoke-free days' => [-1, 3, 7];
        yield 'more check-ins than days' => [0, 8, 7];
        yield 'more smoke-free days than check-ins' => [4, 3, 7];
    }

    #[DataProvider('impossibleCounts')]
    public function test_FR_COMP_03_impossible_day_counts_are_rejected(int $smokeFreeDays, int $checkIns, int $countedDays): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CompetitionScore(Reduction::fromTotals(50, 100), $smokeFreeDays, $checkIns, $countedDays);
    }
}
