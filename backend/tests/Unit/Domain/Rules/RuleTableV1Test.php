<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Rules;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Competition\CompetitionRules;
use SmokeDetector\Domain\Level\LevelCalculator;
use SmokeDetector\Domain\Level\LevelRules;
use SmokeDetector\Domain\Plan\BaselineRules;
use SmokeDetector\Domain\Plan\PlanRules;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Rules\RuleSet;
use SmokeDetector\Domain\Streak\StreakRules;
use SmokeDetector\Domain\Xp\XpRules;

/**
 * The shipped rule table backend/config/rules/v1.php must hold exactly the numbers of the SRS
 * (Appendix A, FR-PLAN-01, FR-PLAN-04 to FR-PLAN-07, FR-GAME-04).
 *
 * The expected numbers are written out here a second time on purpose. Changing a number in the rule
 * table changes users' results, so it has to be a deliberate edit in two places and in the SRS.
 */
final class RuleTableV1Test extends TestCase
{
    public function test_FR_GAME_01_rule_table_v1_is_versioned_as_v1(): void
    {
        self::assertSame('v1', RuleTables::v1()->version);
    }

    public function test_FR_PLAN_01_v1_taper_factors_are_0_9_gentle_0_8_normal_and_0_7_fast(): void
    {
        $plan = RuleTables::v1()->plan;

        self::assertSame(90, $plan->limitPercent(TaperSpeed::Gentle));
        self::assertSame(80, $plan->limitPercent(TaperSpeed::Normal));
        self::assertSame(70, $plan->limitPercent(TaperSpeed::Fast));
    }

    public function test_FR_PLAN_04_v1_baseline_is_0_7_week_mean_plus_0_3_mean_of_28_days(): void
    {
        $baseline = RuleTables::v1()->baseline;

        self::assertSame(7, $baseline->weekWeight);
        self::assertSame(3, $baseline->monthWeight);
        self::assertSame(7, $baseline->weekDays);
        self::assertSame(28, $baseline->monthDays);
    }

    public function test_FR_PLAN_05_v1_baseline_needs_4_checked_in_days_of_the_last_7(): void
    {
        self::assertSame(4, RuleTables::v1()->baseline->minCheckedInWeekDays);
    }

    public function test_FR_PLAN_06_v1_baseline_may_rise_by_10_percent_or_0_5_sessions_per_day(): void
    {
        $baseline = RuleTables::v1()->baseline;

        self::assertSame(10, $baseline->maxIncreasePercent);
        self::assertSame(5, $baseline->minIncreaseStepTenths);
    }

    public function test_FR_PLAN_07_v1_scoring_minimum_of_the_frozen_baseline_is_1_0(): void
    {
        self::assertSame(10, RuleTables::v1()->competition->minScoringBaselineTenths);
    }

    public function test_FR_GAME_04_v1_freeze_is_earned_every_7_days_and_at_most_2_are_held(): void
    {
        $streak = RuleTables::v1()->streak;

        self::assertSame(7, $streak->freezeInterval);
        self::assertSame(2, $streak->maxFreezesHeld);
    }

    public function test_FR_GAME_01_v1_streak_milestones_are_7_30_100_and_365_days(): void
    {
        self::assertSame([7, 30, 100, 365], RuleTables::v1()->streak->milestoneThresholds);
    }

    public function test_FR_GAME_01_v1_day_xp_is_10_for_check_in_20_under_limit_and_40_smoke_free(): void
    {
        $xp = RuleTables::v1()->xp;

        self::assertSame(10, $xp->checkIn);
        self::assertSame(20, $xp->underLimit);
        self::assertSame(40, $xp->smokeFree);
    }

    public function test_FR_GAME_01_v1_streak_milestone_xp_is_50_150_500_and_1500(): void
    {
        self::assertSame([7 => 50, 30 => 150, 100 => 500, 365 => 1500], RuleTables::v1()->xp->streakMilestones);
    }

    public function test_FR_GAME_01_v1_duel_xp_is_100_won_50_draw_20_lost_and_0_forfeited(): void
    {
        $xp = RuleTables::v1()->xp;

        self::assertSame(100, $xp->duelWon);
        self::assertSame(50, $xp->duelDraw);
        self::assertSame(20, $xp->duelLost);
        self::assertSame(0, $xp->duelForfeited);
    }

    public function test_FR_GAME_01_v1_league_xp_is_150_100_50_from_3_participants_times_1_2_3_4(): void
    {
        $xp = RuleTables::v1()->xp;

        self::assertSame([1 => 150, 2 => 100, 3 => 50], $xp->leagueRankXp);
        self::assertSame(3, $xp->leagueMinParticipants);
        self::assertSame(1, $xp->leagueWeekMultiplier);
        self::assertSame(2, $xp->leagueMonthMultiplier);
        self::assertSame(3, $xp->leagueQuarterMultiplier);
        self::assertSame(4, $xp->leagueHalfYearMultiplier);
    }

    public function test_FR_GAME_01_v1_reaction_xp_is_2_with_at_most_10_per_day(): void
    {
        $xp = RuleTables::v1()->xp;

        self::assertSame(2, $xp->reaction);
        self::assertSame(10, $xp->reactionDailyCap);
    }

    public function test_FR_GAME_05_v1_level_n_requires_50_times_n_times_n_plus_1_xp(): void
    {
        $level = RuleTables::v1()->level;
        $calculator = new LevelCalculator($level);

        self::assertSame(50, $level->factor);
        // Appendix A: L1 = 100, L2 = 300, L3 = 600.
        self::assertSame(100, $calculator->xpRequiredFor(1));
        self::assertSame(300, $calculator->xpRequiredFor(2));
        self::assertSame(600, $calculator->xpRequiredFor(3));
    }

    /**
     * The whole file at once: a new, renamed or removed key cannot slip past the tests above.
     */
    public function test_FR_GAME_01_rule_table_v1_holds_exactly_the_srs_numbers_and_nothing_else(): void
    {
        self::assertSame(
            [
                'version' => 'v1',
                'plan' => [
                    'gentleLimitPercent' => 90,
                    'normalLimitPercent' => 80,
                    'fastLimitPercent' => 70,
                ],
                'baseline' => [
                    'weekWeight' => 7,
                    'monthWeight' => 3,
                    'weekDays' => 7,
                    'monthDays' => 28,
                    'minCheckedInWeekDays' => 4,
                    'maxIncreasePercent' => 10,
                    'minIncreaseStepTenths' => 5,
                ],
                'streak' => [
                    'freezeInterval' => 7,
                    'maxFreezesHeld' => 2,
                    'milestoneThresholds' => [7, 30, 100, 365],
                ],
                'xp' => [
                    'checkIn' => 10,
                    'underLimit' => 20,
                    'smokeFree' => 40,
                    'streakMilestones' => [7 => 50, 30 => 150, 100 => 500, 365 => 1500],
                    'duelWon' => 100,
                    'duelDraw' => 50,
                    'duelLost' => 20,
                    'duelForfeited' => 0,
                    'leagueRankXp' => [1 => 150, 2 => 100, 3 => 50],
                    'leagueMinParticipants' => 3,
                    'leagueWeekMultiplier' => 1,
                    'leagueMonthMultiplier' => 2,
                    'leagueQuarterMultiplier' => 3,
                    'leagueHalfYearMultiplier' => 4,
                    'reaction' => 2,
                    'reactionDailyCap' => 10,
                ],
                'level' => [
                    'factor' => 50,
                ],
                'competition' => [
                    'minScoringBaselineTenths' => 10,
                ],
            ],
            RuleTables::v1Array(),
        );
    }

    /**
     * Calculators built without a rule set (mostly in tests) must behave like the shipped table.
     */
    public function test_FR_GAME_01_constructor_defaults_of_every_rules_class_equal_rule_table_v1(): void
    {
        $v1 = RuleTables::v1();

        self::assertEquals(new PlanRules(), $v1->plan);
        self::assertEquals(new BaselineRules(), $v1->baseline);
        self::assertEquals(new StreakRules(), $v1->streak);
        self::assertEquals(new XpRules(), $v1->xp);
        self::assertEquals(new LevelRules(), $v1->level);
        self::assertEquals(new CompetitionRules(), $v1->competition);
    }

    public function test_FR_GAME_01_default_rules_are_identical_to_v1_in_type_too(): void
    {
        // assertEquals compares loosely (10 == "10"), so the property values are compared strictly as well.
        $v1 = RuleTables::v1();

        self::assertSame(get_object_vars(new PlanRules()), get_object_vars($v1->plan));
        self::assertSame(get_object_vars(new BaselineRules()), get_object_vars($v1->baseline));
        self::assertSame(get_object_vars(new StreakRules()), get_object_vars($v1->streak));
        self::assertSame(get_object_vars(new XpRules()), get_object_vars($v1->xp));
        self::assertSame(get_object_vars(new LevelRules()), get_object_vars($v1->level));
        self::assertSame(get_object_vars(new CompetitionRules()), get_object_vars($v1->competition));
    }

    /**
     * NFR-04 depends on finding the numbers of a stored version again, so the name inside a rule
     * table must be the name of its file.
     */
    public function test_NFR_04_every_rule_table_file_is_valid_and_named_after_its_version(): void
    {
        $files = glob(RuleTables::directory() . '/*.php');

        self::assertNotFalse($files);
        self::assertContains(RuleTables::directory() . '/v1.php', $files);
        foreach ($files as $file) {
            self::assertSame(basename($file, '.php'), RuleSet::fromArray(RuleTables::load($file))->version);
        }
    }

    public function test_NFR_04_loading_rule_table_v1_twice_gives_equal_rule_sets(): void
    {
        // Every number written out, not taken from the constructor defaults: a loader that is wrong
        // in the same way on every call must fail here too.
        $expected = new RuleSet(
            'v1',
            new PlanRules(gentleLimitPercent: 90, normalLimitPercent: 80, fastLimitPercent: 70),
            new BaselineRules(
                weekWeight: 7,
                monthWeight: 3,
                weekDays: 7,
                monthDays: 28,
                minCheckedInWeekDays: 4,
                maxIncreasePercent: 10,
                minIncreaseStepTenths: 5,
            ),
            new StreakRules(freezeInterval: 7, maxFreezesHeld: 2, milestoneThresholds: [7, 30, 100, 365]),
            new XpRules(
                checkIn: 10,
                underLimit: 20,
                smokeFree: 40,
                streakMilestones: [7 => 50, 30 => 150, 100 => 500, 365 => 1500],
                duelWon: 100,
                duelDraw: 50,
                duelLost: 20,
                duelForfeited: 0,
                leagueRankXp: [1 => 150, 2 => 100, 3 => 50],
                leagueMinParticipants: 3,
                leagueWeekMultiplier: 1,
                leagueMonthMultiplier: 2,
                leagueQuarterMultiplier: 3,
                leagueHalfYearMultiplier: 4,
                reaction: 2,
                reactionDailyCap: 10,
            ),
            new LevelRules(factor: 50),
            new CompetitionRules(minScoringBaselineTenths: 10),
        );

        self::assertEquals($expected, RuleTables::v1());
        self::assertEquals($expected, RuleTables::v1());
        self::assertEquals(RuleTables::v1(), RuleTables::v1());
    }
}
