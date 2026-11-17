<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Level;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Level\LevelCalculator;
use SmokeDetector\Domain\Level\LevelProgress;
use SmokeDetector\Domain\Level\LevelRules;
use SmokeDetector\Domain\Xp\XpRules;

/**
 * Levels from cumulative XP, SRS FR-GAME-05: level n requires 50 × n × (n + 1) XP (Appendix A).
 */
final class LevelCalculatorTest extends TestCase
{
    /** Highest level whose threshold still fits into a 64-bit integer with the default factor of 50. */
    private const int MAX_LEVEL = 429_496_729;

    private LevelCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new LevelCalculator(new LevelRules());
    }

    // ---- Thresholds ---------------------------------------------------------------------------

    #[DataProvider('thresholds')]
    public function test_FR_GAME_05_level_n_requires_50_times_n_times_n_plus_1_xp(int $level, int $xp): void
    {
        self::assertSame($xp, $this->calculator->xpRequiredFor($level));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function thresholds(): iterable
    {
        yield 'level 0' => [0, 0];
        yield 'level 1' => [1, 100];
        yield 'level 2' => [2, 300];
        yield 'level 3' => [3, 600];
        yield 'level 4' => [4, 1000];
        yield 'level 10' => [10, 5500];
        yield 'level 100' => [100, 505_000];
    }

    public function test_FR_GAME_05_new_user_starts_at_level_0(): void
    {
        self::assertSame(0, $this->calculator->levelFor(0));
    }

    #[DataProvider('levelsAtAndBelowThresholds')]
    public function test_FR_GAME_05_level_changes_exactly_at_the_threshold(int $totalXp, int $level): void
    {
        self::assertSame($level, $this->calculator->levelFor($totalXp));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function levelsAtAndBelowThresholds(): iterable
    {
        yield '1 XP' => [1, 0];
        yield '99 XP' => [99, 0];
        yield '100 XP' => [100, 1];
        yield '101 XP' => [101, 1];
        yield '299 XP' => [299, 1];
        yield '300 XP' => [300, 2];
        yield '599 XP' => [599, 2];
        yield '600 XP' => [600, 3];
        yield '999 XP' => [999, 3];
        yield '1000 XP' => [1000, 4];
        yield '5499 XP' => [5499, 9];
        yield '5500 XP' => [5500, 10];
    }

    public function test_FR_GAME_05_negative_xp_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->levelFor(-1);
    }

    public function test_FR_GAME_05_negative_level_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->xpRequiredFor(-1);
    }

    // ---- Consistency over a swept range -------------------------------------------------------

    public function test_FR_GAME_05_level_is_correct_at_every_threshold_and_one_xp_below_it(): void
    {
        for ($level = 1; $level <= 3000; $level++) {
            $threshold = $this->calculator->xpRequiredFor($level);

            self::assertSame($level, $this->calculator->levelFor($threshold), "at the threshold of level {$level}");
            self::assertSame($level - 1, $this->calculator->levelFor($threshold - 1), "one XP below level {$level}");
        }
    }

    public function test_FR_GAME_05_level_never_decreases_and_never_skips_as_xp_grows(): void
    {
        $previous = 0;
        for ($xp = 0; $xp <= 30_000; $xp++) {
            $level = $this->calculator->levelFor($xp);

            self::assertContains($level - $previous, [0, 1], "at {$xp} XP");
            self::assertLessThanOrEqual($xp, $this->calculator->xpRequiredFor($level), "at {$xp} XP");
            self::assertGreaterThan($xp, $this->calculator->xpRequiredFor($level + 1), "at {$xp} XP");
            $previous = $level;
        }

        self::assertSame(24, $previous);
    }

    // ---- Very large XP ------------------------------------------------------------------------

    public function test_FR_GAME_05_level_is_exact_where_a_float_square_root_no_longer_is(): void
    {
        // From about level 95 million on, n × (n + 1) is above 2^53 and a float cannot hold it exactly.
        $firstLevels = [94_906_265, 123_456_789, 300_000_000, self::MAX_LEVEL - 2000];

        foreach ($firstLevels as $first) {
            for ($level = $first; $level <= $first + 2000; $level++) {
                $threshold = $this->calculator->xpRequiredFor($level);

                self::assertSame($level, $this->calculator->levelFor($threshold), "at level {$level}");
                self::assertSame($level - 1, $this->calculator->levelFor($threshold - 1), "below level {$level}");
            }
        }
    }

    public function test_FR_GAME_05_largest_possible_xp_total_still_has_a_level(): void
    {
        self::assertSame(self::MAX_LEVEL, $this->calculator->levelFor(PHP_INT_MAX));
        self::assertSame(self::MAX_LEVEL, $this->calculator->maxLevel());
        self::assertLessThanOrEqual(PHP_INT_MAX, $this->calculator->xpRequiredFor(self::MAX_LEVEL));
    }

    public function test_FR_GAME_05_level_whose_threshold_does_not_fit_into_an_integer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->xpRequiredFor(self::MAX_LEVEL + 1);
    }

    public function test_FR_GAME_05_level_is_exact_up_to_the_largest_integer_with_a_factor_of_1(): void
    {
        // With a factor of 1 the level is close to the square root of the XP, the hardest case for rounding.
        $calculator = new LevelCalculator(new LevelRules(factor: 1));
        $maxLevel = $calculator->maxLevel();

        self::assertSame(3_037_000_499, $maxLevel);
        self::assertSame($maxLevel, $calculator->levelFor(PHP_INT_MAX));

        for ($level = $maxLevel - 3000; $level <= $maxLevel; $level++) {
            $threshold = $calculator->xpRequiredFor($level);

            self::assertSame($level, $calculator->levelFor($threshold), "at the threshold of level {$level}");
            self::assertSame($level - 1, $calculator->levelFor($threshold - 1), "one XP below level {$level}");
        }
    }

    public function test_FR_GAME_05_level_is_exact_around_large_perfect_squares(): void
    {
        // k² and k² − 1 share one float above 2^53, so a float square root alone is off by one for one of them.
        $calculator = new LevelCalculator(new LevelRules(factor: 1));

        for ($root = 3_037_000_499; $root > 3_037_000_499 - 3000; $root--) {
            $square = $root * $root;

            // (k − 1) × k ≤ k² − 1 < k² < k × (k + 1)
            self::assertSame($root - 1, $calculator->levelFor($square), "at {$root} squared");
            self::assertSame($root - 1, $calculator->levelFor($square - 1), "one below {$root} squared");
            // k² + k is the threshold of level k
            self::assertSame($root, $calculator->levelFor($square + $root), "at the threshold of level {$root}");
            self::assertSame($root - 1, $calculator->levelFor($square + $root - 1), "one below level {$root}");
        }
    }

    // ---- The factor is config, not code -------------------------------------------------------

    public function test_FR_GAME_05_custom_factor_changes_the_thresholds(): void
    {
        $calculator = new LevelCalculator(new LevelRules(factor: 10));

        self::assertSame(20, $calculator->xpRequiredFor(1));
        self::assertSame(60, $calculator->xpRequiredFor(2));
        self::assertSame(0, $calculator->levelFor(19));
        self::assertSame(1, $calculator->levelFor(20));
        self::assertSame(1, $calculator->levelFor(59));
        self::assertSame(2, $calculator->levelFor(60));
        self::assertSame(4, $calculator->levelFor(299));
    }

    public function test_FR_GAME_05_largest_allowed_factor_works_up_to_the_largest_integer(): void
    {
        $calculator = new LevelCalculator(new LevelRules(factor: LevelRules::MAX_FACTOR));
        $maxLevel = $calculator->maxLevel();

        self::assertSame($maxLevel, $calculator->levelFor(PHP_INT_MAX));
        self::assertSame($maxLevel - 1, $calculator->levelFor($calculator->xpRequiredFor($maxLevel) - 1));

        $progress = $calculator->progressFor(PHP_INT_MAX);
        self::assertSame($maxLevel, $progress->level);
        self::assertSame(PHP_INT_MAX - $calculator->xpRequiredFor($maxLevel), $progress->xpIntoLevel);
    }

    // ---- Progress for the UI ------------------------------------------------------------------

    #[DataProvider('progress')]
    public function test_FR_GAME_05_progress_shows_level_xp_into_the_level_and_xp_for_the_next_level(
        int $totalXp,
        int $level,
        int $xpIntoLevel,
        int $xpForNextLevel,
        int $xpRemaining,
    ): void {
        $progress = $this->calculator->progressFor($totalXp);

        self::assertSame($level, $progress->level);
        self::assertSame($xpIntoLevel, $progress->xpIntoLevel);
        self::assertSame($xpForNextLevel, $progress->xpForNextLevel);
        self::assertSame($xpRemaining, $progress->xpRemaining());
    }

    /**
     * @return iterable<string, array{int, int, int, int, int}>
     */
    public static function progress(): iterable
    {
        yield 'new user' => [0, 0, 0, 100, 100];
        yield '99 XP' => [99, 0, 99, 100, 1];
        yield '100 XP' => [100, 1, 0, 200, 200];
        yield '250 XP' => [250, 1, 150, 200, 50];
        yield '299 XP' => [299, 1, 199, 200, 1];
        yield '300 XP' => [300, 2, 0, 300, 300];
        yield '777 XP' => [777, 3, 177, 400, 223];
    }

    public function test_FR_GAME_05_progress_is_consistent_with_the_thresholds_over_a_swept_range(): void
    {
        for ($xp = 0; $xp <= 10_000; $xp += 7) {
            $progress = $this->calculator->progressFor($xp);

            self::assertSame($this->calculator->levelFor($xp), $progress->level);
            self::assertSame($xp, $this->calculator->xpRequiredFor($progress->level) + $progress->xpIntoLevel);
            self::assertSame(
                $this->calculator->xpRequiredFor($progress->level + 1),
                $xp + $progress->xpRemaining(),
            );
            self::assertGreaterThan($progress->xpIntoLevel, $progress->xpForNextLevel);
        }
    }

    public function test_FR_GAME_05_progress_works_for_the_largest_possible_xp_total(): void
    {
        $progress = $this->calculator->progressFor(PHP_INT_MAX);

        self::assertSame(self::MAX_LEVEL, $progress->level);
        self::assertSame(PHP_INT_MAX - $this->calculator->xpRequiredFor(self::MAX_LEVEL), $progress->xpIntoLevel);
        self::assertSame(100 * (self::MAX_LEVEL + 1), $progress->xpForNextLevel);
        self::assertGreaterThan(0, $progress->xpRemaining());
    }

    public function test_FR_GAME_05_progress_for_negative_xp_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->progressFor(-1);
    }

    public function test_FR_GAME_05_progress_with_a_negative_level_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LevelProgress(level: -1, xpIntoLevel: 0, xpForNextLevel: 100);
    }

    public function test_FR_GAME_05_progress_with_negative_xp_into_the_level_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LevelProgress(level: 0, xpIntoLevel: -1, xpForNextLevel: 100);
    }

    public function test_FR_GAME_05_progress_that_already_fills_the_level_is_rejected(): void
    {
        // With 100 of 100 XP the user would already be on the next level.
        $this->expectException(InvalidArgumentException::class);

        new LevelProgress(level: 0, xpIntoLevel: 100, xpForNextLevel: 100);
    }

    // ---- Level-ups ----------------------------------------------------------------------------

    public function test_FR_GAME_05_crossing_one_threshold_gains_that_level(): void
    {
        self::assertSame([1], $this->calculator->levelsGained(xpBefore: 90, xpAfter: 110));
        self::assertSame([1], $this->calculator->levelsGained(xpBefore: 99, xpAfter: 100));
    }

    public function test_FR_GAME_05_one_big_award_can_gain_several_levels_in_order(): void
    {
        self::assertSame([1, 2, 3], $this->calculator->levelsGained(xpBefore: 0, xpAfter: 650));
        self::assertSame([3, 4], $this->calculator->levelsGained(xpBefore: 599, xpAfter: 1499));
    }

    public function test_FR_GAME_05_xp_gain_inside_one_level_gains_no_level(): void
    {
        self::assertSame([], $this->calculator->levelsGained(xpBefore: 100, xpAfter: 299));
        self::assertSame([], $this->calculator->levelsGained(xpBefore: 0, xpAfter: 99));
    }

    public function test_FR_GAME_05_unchanged_xp_gains_no_level(): void
    {
        self::assertSame([], $this->calculator->levelsGained(xpBefore: 300, xpAfter: 300));
        self::assertSame([], $this->calculator->levelsGained(xpBefore: 0, xpAfter: 0));
    }

    public function test_FR_GAME_05_levels_gained_rejects_xp_that_went_down(): void
    {
        // XP is only ever added. A lower total means the caller mixed up its numbers.
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->levelsGained(xpBefore: 300, xpAfter: 299);
    }

    public function test_FR_GAME_05_levels_gained_rejects_negative_xp_before(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->levelsGained(xpBefore: -1, xpAfter: 100);
    }

    public function test_FR_GAME_05_levels_gained_rejects_negative_xp_after(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->levelsGained(xpBefore: -5, xpAfter: -1);
    }

    public function test_FR_GAME_05_levels_gained_over_an_absurd_xp_jump_is_rejected_instead_of_exhausting_memory(): void
    {
        // One list entry per level: 0 to the largest integer would be 429 million entries.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('More than 1000000 levels cannot be gained in one step.');

        $this->calculator->levelsGained(xpBefore: 0, xpAfter: PHP_INT_MAX);
    }

    public function test_FR_GAME_05_most_levels_that_can_be_gained_in_one_step_are_still_listed(): void
    {
        $calculator = new LevelCalculator(new LevelRules(factor: 1));
        $max = LevelCalculator::MAX_LEVELS_GAINED;

        $levels = $calculator->levelsGained(xpBefore: 0, xpAfter: $calculator->xpRequiredFor($max));
        $higherUp = $calculator->levelsGained(
            xpBefore: $calculator->xpRequiredFor(7),
            xpAfter: $calculator->xpRequiredFor($max + 7),
        );

        self::assertSame(1_000_000, $max);
        self::assertCount($max, $levels);
        self::assertSame(1, $levels[0]);
        self::assertSame($max, $levels[$max - 1]);
        self::assertCount($max, $higherUp);
        self::assertSame(8, $higherUp[0]);
        self::assertSame($max + 7, $higherUp[$max - 1]);
    }

    public function test_FR_GAME_05_one_level_more_than_can_be_gained_in_one_step_is_rejected(): void
    {
        $calculator = new LevelCalculator(new LevelRules(factor: 1));

        $this->expectException(InvalidArgumentException::class);

        $calculator->levelsGained(
            xpBefore: 0,
            xpAfter: $calculator->xpRequiredFor(LevelCalculator::MAX_LEVELS_GAINED + 1),
        );
    }

    public function test_FR_GAME_05_largest_award_any_rule_table_can_pay_never_gains_too_many_levels(): void
    {
        // The largest single award is a league rank: XpRules::MAX_VALUE XP times a multiplier of XpRules::MAX_VALUE.
        $calculator = new LevelCalculator(new LevelRules(factor: 1));
        $largestAward = XpRules::MAX_VALUE * XpRules::MAX_VALUE;

        self::assertCount(999_999, $calculator->levelsGained(xpBefore: 0, xpAfter: $largestAward));
    }

    // ---- Determinism --------------------------------------------------------------------------

    public function test_NFR_04_same_xp_and_same_rules_always_give_the_identical_level_and_progress(): void
    {
        $totals = [0, 1, 99, 100, 299, 300, 12_345, 987_654_321, 9_007_199_254_740_993, PHP_INT_MAX];
        $other = new LevelCalculator(new LevelRules());

        foreach ($totals as $totalXp) {
            $level = $this->calculator->levelFor($totalXp);
            $progress = $this->calculator->progressFor($totalXp);

            for ($run = 0; $run < 5; $run++) {
                self::assertSame($level, $this->calculator->levelFor($totalXp));
                self::assertSame($level, $other->levelFor($totalXp));
                self::assertEquals($progress, $other->progressFor($totalXp));
            }
        }

        self::assertSame(
            $this->calculator->levelsGained(0, 100_000),
            $other->levelsGained(0, 100_000),
        );
    }
}
