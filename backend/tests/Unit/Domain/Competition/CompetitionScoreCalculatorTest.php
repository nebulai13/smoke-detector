<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Competition;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Competition\CompetitionRules;
use SmokeDetector\Domain\Competition\CompetitionScoreCalculator;
use SmokeDetector\Domain\Competition\CompetitionScoreComparator;
use SmokeDetector\Domain\Competition\FrozenBaseline;
use SmokeDetector\Domain\Competition\Reduction;
use SmokeDetector\Domain\Day\DayOutcome;
use SmokeDetector\Domain\Plan\Baseline;

/**
 * Reduction score of one participant over a competition window, SRS FR-COMP-03 and HLD §5.4.
 *
 * The daily limit plays no role in competition scoring, so the tests use an arbitrary one.
 */
final class CompetitionScoreCalculatorTest extends TestCase
{
    private const int LIMIT = 5;

    private CompetitionScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CompetitionScoreCalculator();
    }

    public function test_FR_COMP_03_one_week_duel_matches_the_hand_calculation(): void
    {
        // B0 = 7.4, 7 days: 5, 6, missed (3 logged), 0, 8, 4, missed (nothing logged)
        //   expected  = 7.4 × 7                  = 51.8
        //   actual    = 5 + 6 + 0 + 8 + 4        = 23    (5 checked-in days)
        //             + 2 × 7.4                  = 14.8  (2 missed days count as baseline)
        //                                        = 37.8
        //   reduction = 1 − 37.8 / 51.8 = 14.0 / 51.8 = 0.27027… → 27.0 %
        $score = $this->calculator->score(self::frozen(7.4), [
            DayOutcome::checkedIn(5, self::LIMIT),
            DayOutcome::checkedIn(6, self::LIMIT),
            DayOutcome::missed(3, self::LIMIT),
            DayOutcome::checkedIn(0, self::LIMIT),
            DayOutcome::checkedIn(8, self::LIMIT),
            DayOutcome::checkedIn(4, self::LIMIT),
            DayOutcome::missed(0, self::LIMIT),
        ]);

        self::assertSame(518, $score->reduction->expectedTenths);
        self::assertSame(378, $score->reduction->actualTenths);
        self::assertSame(140, $score->reduction->savedTenths());
        self::assertSame(27.0, $score->reduction->toPercent());
        self::assertSame(27.03, $score->reduction->toPercent(2));
        self::assertSame(1, $score->smokeFreeDays);
        self::assertSame(5, $score->checkIns);
        self::assertSame(7, $score->countedDays);
    }

    public function test_FR_COMP_03_missed_day_counts_as_logged_sessions_when_they_exceed_the_baseline(): void
    {
        // A missed day adds max(B0, logged): 7.4 for 0 and for 3 logged sessions, but 50 for 50 logged.
        $score = $this->calculator->score(self::frozen(7.4), [
            DayOutcome::missed(0, self::LIMIT),
            DayOutcome::missed(3, self::LIMIT),
            DayOutcome::missed(50, self::LIMIT),
            DayOutcome::checkedIn(2, self::LIMIT),
        ]);

        self::assertSame(74 + 74 + 500 + 20, $score->reduction->actualTenths);
        self::assertSame(4 * 74, $score->reduction->expectedTenths);
    }

    public function test_FR_COMP_03_missed_day_with_fewer_sessions_logged_than_the_baseline_counts_as_baseline(): void
    {
        // 7 logged against B0 = 7.4: the unconfirmed count is not trusted as complete, so the day is 7.4.
        $score = $this->calculator->score(self::frozen(7.4), [DayOutcome::missed(7, self::LIMIT)]);

        self::assertSame(74, $score->reduction->actualTenths);
        self::assertSame(0, $score->reduction->savedTenths());
    }

    public function test_FR_COMP_03_missed_day_with_more_sessions_logged_than_the_baseline_counts_as_logged(): void
    {
        // 8 logged against B0 = 7.4: what was logged is the least that was smoked, so the day is 8.
        $score = $this->calculator->score(self::frozen(7.4), [DayOutcome::missed(8, self::LIMIT)]);

        self::assertSame(80, $score->reduction->actualTenths);
        self::assertSame(-6, $score->reduction->savedTenths());
        self::assertSame(-8.1, $score->reduction->toPercent());
    }

    public function test_FR_COMP_03_missed_day_with_nothing_logged_counts_as_baseline(): void
    {
        $score = $this->calculator->score(self::frozen(7.4), [DayOutcome::missed(0, self::LIMIT)]);

        self::assertSame(74, $score->reduction->actualTenths);
        self::assertSame(0.0, $score->reduction->toPercent());
        self::assertSame(0, $score->smokeFreeDays);
    }

    public function test_FR_COMP_03_missed_day_with_exactly_the_baseline_logged_counts_as_that_same_number(): void
    {
        // B0 = 7.0 and 7 logged: both sides of the maximum are 70 tenths.
        $missed = $this->calculator->score(self::frozen(7.0), [DayOutcome::missed(7, self::LIMIT)]);
        $checkedIn = $this->calculator->score(self::frozen(7.0), [DayOutcome::checkedIn(7, self::LIMIT)]);

        self::assertSame(70, $missed->reduction->actualTenths);
        self::assertSame(0, $missed->reduction->savedTenths());
        self::assertTrue($missed->reduction->equals($checkedIn->reduction));
        // The reductions tie, and the check-in tie-breaker still puts the honest day ahead.
        self::assertSame(1, (new CompetitionScoreComparator())->compare($checkedIn, $missed));
    }

    public function test_FR_COMP_03_missed_day_is_never_scored_better_than_the_baseline_or_than_what_was_logged(): void
    {
        foreach ([10, 74, 75, 80, 800] as $baselineTenths) {
            $baseline = FrozenBaseline::freeze(Baseline::fromTenths($baselineTenths), new CompetitionRules());
            for ($logged = 0; $logged <= 100; $logged++) {
                $actual = $this->calculator->score($baseline, [DayOutcome::missed($logged, self::LIMIT)])
                    ->reduction->actualTenths;

                self::assertSame(max($baselineTenths, 10 * $logged), $actual, "B0={$baselineTenths} logged={$logged}");
            }
        }
    }

    public function test_FR_COMP_03_skipping_the_check_in_never_scores_better_than_checking_in(): void
    {
        // Fixed seed, so a failure can be reproduced (NFR-04).
        mt_srand(20261117);
        $comparator = new CompetitionScoreComparator();

        for ($i = 0; $i < 2000; $i++) {
            $baseline = FrozenBaseline::freeze(Baseline::fromTenths(mt_rand(0, 800)), new CompetitionRules());
            $days = [];
            for ($d = mt_rand(0, 29); $d > 0; $d--) {
                $days[] = mt_rand(0, 1) === 1
                    ? DayOutcome::checkedIn(mt_rand(0, 60), self::LIMIT)
                    : DayOutcome::missed(mt_rand(0, 60), self::LIMIT);
            }
            // The same last day with the same logs, once confirmed and once not.
            $count = mt_rand(0, 120);
            $honest = $this->calculator->score($baseline, [...$days, DayOutcome::checkedIn($count, self::LIMIT)]);
            $skipped = $this->calculator->score($baseline, [...$days, DayOutcome::missed($count, self::LIMIT)]);

            $context = "B0={$baseline->tenths} count={$count}";
            self::assertGreaterThanOrEqual(0, $honest->reduction->compareTo($skipped->reduction), $context);
            self::assertSame(1, $comparator->compare($honest, $skipped), $context);
        }
    }

    public function test_FR_COMP_03_all_days_missed_is_exactly_zero_reduction(): void
    {
        $score = $this->calculator->score(self::frozen(7.4), self::days(7, DayOutcome::missed(2, self::LIMIT)));

        self::assertSame(0, $score->reduction->savedTenths());
        self::assertTrue($score->reduction->equals(Reduction::fromTotals(1, 1)));
        self::assertSame(0.0, $score->reduction->toPercent());
        self::assertSame(0, $score->checkIns);
        self::assertSame(0, $score->smokeFreeDays);
        self::assertSame(7, $score->countedDays);
    }

    public function test_FR_COMP_03_smoking_exactly_the_baseline_is_exactly_zero_reduction(): void
    {
        $checkedIn = $this->calculator->score(self::frozen(6.0), self::days(7, DayOutcome::checkedIn(6, self::LIMIT)));
        $missed = $this->calculator->score(self::frozen(7.4), self::days(30, DayOutcome::missed(0, self::LIMIT)));

        self::assertSame(0, $checkedIn->reduction->savedTenths());
        self::assertTrue($checkedIn->reduction->equals($missed->reduction));
    }

    public function test_FR_COMP_03_all_days_smoke_free_is_exactly_100_percent(): void
    {
        $score = $this->calculator->score(self::frozen(7.4), self::days(30, DayOutcome::checkedIn(0, self::LIMIT)));

        self::assertSame(0, $score->reduction->actualTenths);
        self::assertTrue($score->reduction->equals(Reduction::fromTotals(0, 1)));
        self::assertSame(100.0, $score->reduction->toPercent());
        self::assertSame(30, $score->smokeFreeDays);
        self::assertSame(30, $score->checkIns);
    }

    public function test_FR_COMP_03_smoking_more_than_baseline_gives_a_negative_reduction(): void
    {
        // expected = 5.0 × 7 = 35, actual = 6 × 7 = 42, reduction = 1 − 42 / 35 = −0.2
        $score = $this->calculator->score(self::frozen(5.0), self::days(7, DayOutcome::checkedIn(6, self::LIMIT)));

        self::assertSame(-70, $score->reduction->savedTenths());
        self::assertSame(-20.0, $score->reduction->toPercent());
        self::assertSame(-1, $score->reduction->compareTo(Reduction::fromTotals(1, 1)));
    }

    public function test_FR_COMP_03_smoke_free_days_and_check_ins_are_counted_for_the_tie_breakers(): void
    {
        $score = $this->calculator->score(self::frozen(4.0), [
            DayOutcome::checkedIn(0, self::LIMIT),
            DayOutcome::checkedIn(0, self::LIMIT),
            DayOutcome::checkedIn(1, self::LIMIT),
            // Nothing logged, but never confirmed: not a smoke-free day and not a check-in.
            DayOutcome::missed(0, self::LIMIT),
            DayOutcome::missed(2, self::LIMIT),
        ]);

        self::assertSame(2, $score->smokeFreeDays);
        self::assertSame(3, $score->checkIns);
        self::assertSame(5, $score->countedDays);
    }

    public function test_FR_COMP_03_same_fraction_from_different_baselines_is_a_tie_on_reduction(): void
    {
        // 5 a day against a baseline of 10.0, and 10 a day against 20.0: both exactly 50 %.
        $light = $this->calculator->score(self::frozen(10.0), self::days(7, DayOutcome::checkedIn(5, self::LIMIT)));
        $heavy = $this->calculator->score(self::frozen(20.0), self::days(7, DayOutcome::checkedIn(10, self::LIMIT)));

        self::assertTrue($light->reduction->equals($heavy->reduction));
        self::assertSame(0, $light->reduction->compareTo($heavy->reduction));
    }

    public function test_FR_COMP_03_one_session_more_over_six_months_is_not_a_tie(): void
    {
        $steady = self::days(180, DayOutcome::checkedIn(10, self::LIMIT));
        $oneMore = [...self::days(179, DayOutcome::checkedIn(10, self::LIMIT)), DayOutcome::checkedIn(11, self::LIMIT)];

        $a = $this->calculator->score(self::frozen(20.0), $steady);
        $b = $this->calculator->score(self::frozen(20.0), $oneMore);

        // 1 − 1800 / 3600 = 50 % against 1 − 1801 / 3600 = 49.97 %: the same on screen, not a tie.
        self::assertSame($a->reduction->toPercent(), $b->reduction->toPercent());
        self::assertSame(1, $a->reduction->compareTo($b->reduction));
    }

    public function test_FR_COMP_03_the_daily_limit_does_not_change_the_score(): void
    {
        $strict = $this->calculator->score(self::frozen(7.4), self::days(7, DayOutcome::checkedIn(4, 0)));
        $loose = $this->calculator->score(self::frozen(7.4), self::days(7, DayOutcome::checkedIn(4, 40)));

        self::assertEquals($strict, $loose);
    }

    public function test_FR_COMP_03_window_without_counted_days_is_rejected(): void
    {
        // 0 / 0 is not a reduction. Rating it as 0 % would put a user who has not played yet above
        // everyone who is over baseline, so the caller has to leave such a participant unrated.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A window without counted days cannot be scored.');

        $this->calculator->score(self::frozen(7.4), []);
    }

    public function test_FR_COMP_03_window_of_fewer_days_is_rated_over_its_counted_days_only(): void
    {
        // Expected = B0 × counted days, so 3 days at half the baseline are the same 50 % as 30 such days.
        // Which days count for someone who joins a league late is decided by the league code (not built yet).
        $joiner = $this->calculator->score(self::frozen(8.0), self::days(3, DayOutcome::checkedIn(4, self::LIMIT)));
        $fullMonth = $this->calculator->score(self::frozen(8.0), self::days(30, DayOutcome::checkedIn(4, self::LIMIT)));

        self::assertSame(3, $joiner->countedDays);
        self::assertSame(240, $joiner->reduction->expectedTenths);
        self::assertSame(120, $joiner->reduction->actualTenths);
        self::assertTrue($joiner->reduction->equals($fullMonth->reduction));
    }

    public function test_FR_PLAN_07_baseline_below_1_0_is_scored_as_1_0(): void
    {
        // Live baseline 0.3, one session in a week. Scored against 1.0:
        //   expected = 1.0 × 7 = 7, actual = 1, reduction = 6 / 7 = 85.7 %
        // Against the raw 0.3 it would be 1 − 1 / 2.1 = 52.4 %.
        $score = $this->calculator->score(self::frozen(0.3), [
            DayOutcome::checkedIn(1, self::LIMIT),
            ...self::days(6, DayOutcome::checkedIn(0, self::LIMIT)),
        ]);

        self::assertSame(70, $score->reduction->expectedTenths);
        self::assertSame(10, $score->reduction->actualTenths);
        self::assertSame(85.7, $score->reduction->toPercent());
    }

    public function test_FR_PLAN_07_missed_day_of_a_low_baseline_counts_as_the_minimum_too(): void
    {
        // Expected and missed days use the same B0, otherwise an all-missed window would not be 0 %.
        $score = $this->calculator->score(self::frozen(0.0), self::days(7, DayOutcome::missed(0, self::LIMIT)));

        self::assertSame(70, $score->reduction->expectedTenths);
        self::assertSame(70, $score->reduction->actualTenths);
    }

    public function test_FR_PLAN_07_baseline_above_the_minimum_is_scored_as_it_was_frozen(): void
    {
        $score = $this->calculator->score(self::frozen(7.4), self::days(7, DayOutcome::checkedIn(0, self::LIMIT)));

        self::assertSame(518, $score->reduction->expectedTenths);
    }

    public function test_FR_GAME_04_day_protected_by_a_streak_freeze_still_counts_as_baseline(): void
    {
        // A freeze only protects the check-in streak. Scoring is not even told about it: a day without
        // a check-in is a missed day, so it adds B0 to the actual total and is not a check-in.
        $frozenDay = DayOutcome::missed(0, self::LIMIT);

        $score = $this->calculator->score(self::frozen(7.4), [
            DayOutcome::checkedIn(0, self::LIMIT),
            $frozenDay,
        ]);

        self::assertSame(74, $score->reduction->actualTenths);
        self::assertSame(50.0, $score->reduction->toPercent());
        self::assertSame(1, $score->checkIns);
        self::assertSame(1, $score->smokeFreeDays);
    }

    public function test_FR_GAME_04_day_protected_by_a_streak_freeze_counts_as_logged_when_that_is_above_the_baseline(): void
    {
        // The freeze changes nothing here either: 9 logged on the frozen day is above B0 = 7.4, so it counts as 9.
        $frozenDay = DayOutcome::missed(9, self::LIMIT);

        $score = $this->calculator->score(self::frozen(7.4), [
            DayOutcome::checkedIn(0, self::LIMIT),
            $frozenDay,
        ]);

        self::assertSame(90, $score->reduction->actualTenths);
        self::assertSame(148, $score->reduction->expectedTenths);
        self::assertSame(1, $score->checkIns);
    }

    public function test_FR_COMP_03_realistic_extremes_are_scored_exactly(): void
    {
        // Highest onboarding baseline, longest calendar half-year, a few hundred sessions a day.
        $score = $this->calculator->score(self::frozen(80.0), self::days(184, DayOutcome::checkedIn(300, self::LIMIT)));

        self::assertSame(147_200, $score->reduction->expectedTenths);
        self::assertSame(552_000, $score->reduction->actualTenths);
        self::assertSame(-275.0, $score->reduction->toPercent());
    }

    public function test_FR_COMP_03_single_count_too_large_to_score_exactly_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->score(self::frozen(7.4), [DayOutcome::checkedIn(PHP_INT_MAX, self::LIMIT)]);
    }

    public function test_FR_COMP_03_sum_of_counts_too_large_to_score_exactly_is_rejected(): void
    {
        // 150 million sessions are 1.5 billion tenths. Two such days pass the 2^31 − 1 bound.
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->score(self::frozen(7.4), self::days(2, DayOutcome::checkedIn(150_000_000, self::LIMIT)));
    }

    public function test_FR_COMP_03_baseline_too_large_to_score_exactly_is_rejected(): void
    {
        $absurd = FrozenBaseline::freeze(Baseline::fromTenths(PHP_INT_MAX), new CompetitionRules());

        $this->expectException(InvalidArgumentException::class);

        $this->calculator->score($absurd, self::days(2, DayOutcome::missed(0, self::LIMIT)));
    }

    public function test_FR_COMP_03_expected_total_too_large_to_score_exactly_is_rejected(): void
    {
        $large = FrozenBaseline::freeze(Baseline::fromTenths(1_500_000_000), new CompetitionRules());

        $this->expectException(InvalidArgumentException::class);

        $this->calculator->score($large, self::days(2, DayOutcome::checkedIn(0, self::LIMIT)));
    }

    public function test_FR_COMP_03_window_of_a_single_day_is_scored(): void
    {
        $score = $this->calculator->score(self::frozen(7.4), [DayOutcome::checkedIn(5, self::LIMIT)]);

        self::assertSame(1, $score->countedDays);
        self::assertSame(1, $score->checkIns);
        self::assertSame(74, $score->reduction->expectedTenths);
        self::assertSame(50, $score->reduction->actualTenths);
    }

    public function test_FR_COMP_03_totals_of_exactly_the_guaranteed_bound_are_still_scored(): void
    {
        // Expected and actual both land exactly on 2^31 − 1 tenths: the last value that compares exactly.
        $atBound = FrozenBaseline::freeze(Baseline::fromTenths(Reduction::MAX_TENTHS), new CompetitionRules());

        $score = $this->calculator->score($atBound, [DayOutcome::missed(0, self::LIMIT)]);

        self::assertSame(Reduction::MAX_TENTHS, $score->reduction->expectedTenths);
        self::assertSame(Reduction::MAX_TENTHS, $score->reduction->actualTenths);
    }

    public function test_FR_COMP_03_total_reaching_the_guaranteed_bound_over_several_days_is_still_scored(): void
    {
        // 2147483647 = 2 × 1073741823 + 1: two days of B0 and a third day that fills the last tenth.
        $half = FrozenBaseline::freeze(Baseline::fromTenths(1_073_741_823), new CompetitionRules());
        $one = FrozenBaseline::freeze(Baseline::fromTenths(1), new CompetitionRules(minScoringBaselineTenths: 1));

        $twoDays = $this->calculator->score($half, self::days(2, DayOutcome::missed(0, self::LIMIT)));
        $heavyDay = $this->calculator->score($one, [
            DayOutcome::checkedIn(214_748_364, self::LIMIT),
            ...self::days(7, DayOutcome::missed(0, self::LIMIT)),
        ]);

        self::assertSame(Reduction::MAX_TENTHS - 1, $twoDays->reduction->actualTenths);
        self::assertSame(Reduction::MAX_TENTHS, $heavyDay->reduction->actualTenths);
        self::assertSame(8, $heavyDay->reduction->expectedTenths);
    }

    public function test_FR_COMP_03_sum_of_days_one_tenth_above_the_guaranteed_bound_is_rejected_while_adding(): void
    {
        // Rejected at the day that crosses the bound, before a sum could leave the integer range.
        $one = FrozenBaseline::freeze(Baseline::fromTenths(1), new CompetitionRules(minScoringBaselineTenths: 1));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The window totals are too large to be scored exactly.');

        $this->calculator->score($one, [
            DayOutcome::checkedIn(214_748_364, self::LIMIT),
            ...self::days(8, DayOutcome::missed(0, self::LIMIT)),
        ]);
    }

    public function test_FR_COMP_03_expected_total_above_the_guaranteed_bound_is_rejected_while_adding(): void
    {
        $large = FrozenBaseline::freeze(Baseline::fromTenths(1_500_000_000), new CompetitionRules());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The window totals are too large to be scored exactly.');

        $this->calculator->score($large, self::days(2, DayOutcome::checkedIn(0, self::LIMIT)));
    }

    public function test_FR_COMP_03_largest_daily_count_that_fits_the_guaranteed_bound_is_scored(): void
    {
        // 214,748,364 sessions are 2,147,483,640 tenths, the last whole count below 2^31 − 1 tenths.
        $checkedIn = $this->calculator->score(self::frozen(7.4), [DayOutcome::checkedIn(214_748_364, self::LIMIT)]);
        $missed = $this->calculator->score(self::frozen(7.4), [DayOutcome::missed(214_748_364, self::LIMIT)]);

        self::assertSame(2_147_483_640, $checkedIn->reduction->actualTenths);
        self::assertSame(2_147_483_640, $missed->reduction->actualTenths);
    }

    public function test_FR_COMP_03_daily_count_one_above_the_largest_that_fits_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A daily count is too large to be scored exactly.');

        $this->calculator->score(self::frozen(7.4), [DayOutcome::checkedIn(214_748_365, self::LIMIT)]);
    }

    public function test_FR_COMP_03_logged_count_of_a_missed_day_too_large_to_score_exactly_is_rejected(): void
    {
        // The logged count of a missed day is scored when it is above B0, so it has the same bound.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A daily count is too large to be scored exactly.');

        $this->calculator->score(self::frozen(7.4), [DayOutcome::missed(PHP_INT_MAX, self::LIMIT)]);
    }

    public function test_NFR_04_scoring_the_same_days_again_gives_the_identical_score(): void
    {
        $days = [
            DayOutcome::checkedIn(5, self::LIMIT),
            DayOutcome::missed(3, self::LIMIT),
            DayOutcome::checkedIn(0, self::LIMIT),
            DayOutcome::checkedIn(9, self::LIMIT),
        ];

        $first = $this->calculator->score(self::frozen(7.4), $days);
        $second = (new CompetitionScoreCalculator())->score(self::frozen(7.4), $days);

        self::assertEquals($first, $second);
        self::assertSame($first->reduction->toPercent(4), $second->reduction->toPercent(4));
    }

    public function test_NFR_04_order_of_the_days_does_not_change_the_score(): void
    {
        $days = [
            DayOutcome::checkedIn(5, self::LIMIT),
            DayOutcome::missed(3, self::LIMIT),
            DayOutcome::checkedIn(0, self::LIMIT),
            DayOutcome::checkedIn(9, self::LIMIT),
        ];

        self::assertEquals(
            $this->calculator->score(self::frozen(7.4), $days),
            $this->calculator->score(self::frozen(7.4), array_reverse($days)),
        );
    }

    private static function frozen(float $sessionsPerDay): FrozenBaseline
    {
        return FrozenBaseline::freeze(Baseline::of($sessionsPerDay), new CompetitionRules());
    }

    /**
     * @return list<DayOutcome>
     */
    private static function days(int $number, DayOutcome $day): array
    {
        return array_fill(0, $number, $day);
    }
}
