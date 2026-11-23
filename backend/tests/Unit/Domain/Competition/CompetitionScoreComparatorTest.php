<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Competition;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Competition\CompetitionRules;
use SmokeDetector\Domain\Competition\CompetitionScore;
use SmokeDetector\Domain\Competition\CompetitionScoreCalculator;
use SmokeDetector\Domain\Competition\CompetitionScoreComparator;
use SmokeDetector\Domain\Competition\FrozenBaseline;
use SmokeDetector\Domain\Competition\Reduction;
use SmokeDetector\Domain\Day\DayOutcome;
use SmokeDetector\Domain\Plan\Baseline;

/**
 * Winner order of SRS FR-COMP-03: higher reduction, then more smoke-free days, then more check-ins,
 * otherwise a draw. compare() returns 1 when the first score wins.
 */
final class CompetitionScoreComparatorTest extends TestCase
{
    private CompetitionScoreComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new CompetitionScoreComparator();
    }

    public function test_FR_COMP_03_higher_reduction_wins(): void
    {
        $better = new CompetitionScore(Reduction::fromTotals(40, 100), smokeFreeDays: 0, checkIns: 7, countedDays: 7);
        $worse = new CompetitionScore(Reduction::fromTotals(50, 100), smokeFreeDays: 0, checkIns: 7, countedDays: 7);

        self::assertSame(1, $this->comparator->compare($better, $worse));
        self::assertSame(-1, $this->comparator->compare($worse, $better));
    }

    public function test_FR_COMP_03_higher_reduction_wins_even_with_fewer_smoke_free_days_and_check_ins(): void
    {
        $better = new CompetitionScore(Reduction::fromTotals(40, 100), smokeFreeDays: 0, checkIns: 3, countedDays: 7);
        $worse = new CompetitionScore(Reduction::fromTotals(50, 100), smokeFreeDays: 5, checkIns: 7, countedDays: 7);

        self::assertSame(1, $this->comparator->compare($better, $worse));
        self::assertSame(-1, $this->comparator->compare($worse, $better));
    }

    public function test_FR_COMP_03_smallest_exact_difference_in_reduction_decides_before_any_tie_breaker(): void
    {
        // Both show 50.0 %. One tenth of a session in 184 days still decides the duel.
        $better = new CompetitionScore(Reduction::fromTotals(73_600, 147_200), smokeFreeDays: 0, checkIns: 100, countedDays: 184);
        $worse = new CompetitionScore(Reduction::fromTotals(73_601, 147_200), smokeFreeDays: 90, checkIns: 184, countedDays: 184);

        self::assertSame(1, $this->comparator->compare($better, $worse));
        self::assertSame(-1, $this->comparator->compare($worse, $better));
    }

    public function test_FR_COMP_03_equal_reduction_is_decided_by_more_smoke_free_days(): void
    {
        // Both smoke 35 against an expected 70 = exactly 50 %. One packs it into a single day.
        $calculator = new CompetitionScoreCalculator();
        $sixFreeDays = $calculator->score(self::frozen(10.0), [
            ...array_fill(0, 6, DayOutcome::checkedIn(0, 8)),
            DayOutcome::checkedIn(35, 8),
        ]);
        $noFreeDay = $calculator->score(self::frozen(10.0), array_fill(0, 7, DayOutcome::checkedIn(5, 8)));

        self::assertTrue($sixFreeDays->reduction->equals($noFreeDay->reduction));
        self::assertSame(1, $this->comparator->compare($sixFreeDays, $noFreeDay));
        self::assertSame(-1, $this->comparator->compare($noFreeDay, $sixFreeDays));
    }

    public function test_FR_COMP_03_smoke_free_days_decide_before_check_ins(): void
    {
        $moreFreeDays = new CompetitionScore(Reduction::fromTotals(50, 100), smokeFreeDays: 3, checkIns: 4, countedDays: 7);
        $moreCheckIns = new CompetitionScore(Reduction::fromTotals(50, 100), smokeFreeDays: 2, checkIns: 7, countedDays: 7);

        self::assertSame(1, $this->comparator->compare($moreFreeDays, $moreCheckIns));
        self::assertSame(-1, $this->comparator->compare($moreCheckIns, $moreFreeDays));
    }

    public function test_FR_COMP_03_equal_reduction_and_smoke_free_days_are_decided_by_more_check_ins(): void
    {
        // Baseline 10.0, 7 days, both end at 45 against an expected 70 and have no smoke-free day:
        //   seven check-ins: 5 + 5 + 5 + 5 + 5 + 10 + 10 = 45
        //   five check-ins:  5 + 5 + 5 + 5 + 5 + 2 missed days × 10.0 = 45
        $calculator = new CompetitionScoreCalculator();
        $sevenCheckIns = $calculator->score(self::frozen(10.0), [
            ...array_fill(0, 5, DayOutcome::checkedIn(5, 8)),
            ...array_fill(0, 2, DayOutcome::checkedIn(10, 8)),
        ]);
        $fiveCheckIns = $calculator->score(self::frozen(10.0), [
            ...array_fill(0, 5, DayOutcome::checkedIn(5, 8)),
            ...array_fill(0, 2, DayOutcome::missed(0, 8)),
        ]);

        self::assertTrue($sevenCheckIns->reduction->equals($fiveCheckIns->reduction));
        self::assertSame($sevenCheckIns->smokeFreeDays, $fiveCheckIns->smokeFreeDays);
        self::assertSame(1, $this->comparator->compare($sevenCheckIns, $fiveCheckIns));
        self::assertSame(-1, $this->comparator->compare($fiveCheckIns, $sevenCheckIns));
    }

    public function test_FR_COMP_03_everything_equal_is_a_draw(): void
    {
        $a = new CompetitionScore(Reduction::fromTotals(50, 100), smokeFreeDays: 2, checkIns: 6, countedDays: 7);
        $b = new CompetitionScore(Reduction::fromTotals(50, 100), smokeFreeDays: 2, checkIns: 6, countedDays: 7);

        self::assertSame(0, $this->comparator->compare($a, $b));
        self::assertSame(0, $this->comparator->compare($b, $a));
    }

    public function test_FR_COMP_03_different_baselines_with_the_same_fraction_and_tie_breakers_are_a_draw(): void
    {
        // 5 of 10 a day and 10 of 20 a day: neither the absolute numbers nor the baseline break the tie.
        $calculator = new CompetitionScoreCalculator();
        $light = $calculator->score(self::frozen(10.0), array_fill(0, 7, DayOutcome::checkedIn(5, 8)));
        $heavy = $calculator->score(self::frozen(20.0), array_fill(0, 7, DayOutcome::checkedIn(10, 16)));

        self::assertSame(0, $this->comparator->compare($light, $heavy));
        self::assertSame(0, $this->comparator->compare($heavy, $light));
    }

    public function test_FR_COMP_03_two_players_who_never_checked_in_draw(): void
    {
        // Neither logged more than the own baseline, so every day of both counts as exactly B0: 0 % each.
        $calculator = new CompetitionScoreCalculator();
        $a = $calculator->score(self::frozen(7.4), array_fill(0, 7, DayOutcome::missed(0, 5)));
        $b = $calculator->score(self::frozen(12.0), array_fill(0, 7, DayOutcome::missed(12, 9)));

        self::assertSame(0, $this->comparator->compare($a, $b));
        self::assertSame(0, $this->comparator->compare($b, $a));
    }

    public function test_FR_COMP_03_player_who_never_checked_in_and_logged_above_baseline_loses_to_one_at_baseline(): void
    {
        // Without a check-in a day counts as max(B0, logged): 30 a day against 12.0 is −150 %, not 0 %.
        $calculator = new CompetitionScoreCalculator();
        $atBaseline = $calculator->score(self::frozen(7.4), array_fill(0, 7, DayOutcome::missed(0, 5)));
        $aboveBaseline = $calculator->score(self::frozen(12.0), array_fill(0, 7, DayOutcome::missed(30, 9)));

        self::assertSame(-150.0, $aboveBaseline->reduction->toPercent());
        self::assertSame(1, $this->comparator->compare($atBaseline, $aboveBaseline));
        self::assertSame(-1, $this->comparator->compare($aboveBaseline, $atBaseline));
    }

    public function test_FR_COMP_03_number_of_counted_days_is_not_a_tie_breaker(): void
    {
        $shortWindow = new CompetitionScore(Reduction::fromTotals(50, 100), smokeFreeDays: 1, checkIns: 3, countedDays: 3);
        $longWindow = new CompetitionScore(Reduction::fromTotals(150, 300), smokeFreeDays: 1, checkIns: 3, countedDays: 30);

        self::assertSame(0, $this->comparator->compare($shortWindow, $longWindow));
    }

    public function test_NFR_04_comparing_the_same_scores_again_gives_the_same_and_mirrored_result(): void
    {
        $scores = [
            new CompetitionScore(Reduction::fromTotals(0, 70), smokeFreeDays: 7, checkIns: 7, countedDays: 7),
            new CompetitionScore(Reduction::fromTotals(50, 100), smokeFreeDays: 3, checkIns: 4, countedDays: 7),
            new CompetitionScore(Reduction::fromTotals(100, 200), smokeFreeDays: 2, checkIns: 7, countedDays: 7),
            new CompetitionScore(Reduction::fromTotals(100, 200), smokeFreeDays: 2, checkIns: 5, countedDays: 7),
            new CompetitionScore(Reduction::fromTotals(70, 70), smokeFreeDays: 0, checkIns: 0, countedDays: 7),
            new CompetitionScore(Reduction::fromTotals(90, 70), smokeFreeDays: 1, checkIns: 7, countedDays: 7),
        ];

        foreach ($scores as $i => $a) {
            self::assertSame(0, $this->comparator->compare($a, $a));
            foreach ($scores as $j => $b) {
                // The list is written best first, so the order of the list is the order of the rule.
                // Asked twice and mirrored: the same fixed answer each time, whatever was asked before.
                self::assertSame(
                    [$j <=> $i, $j <=> $i, $i <=> $j],
                    [$this->comparator->compare($a, $b), $this->comparator->compare($a, $b), $this->comparator->compare($b, $a)],
                );
            }
        }
    }

    private static function frozen(float $sessionsPerDay): FrozenBaseline
    {
        return FrozenBaseline::freeze(Baseline::of($sessionsPerDay), new CompetitionRules());
    }
}
