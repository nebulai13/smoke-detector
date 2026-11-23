<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Day;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Competition\CompetitionRules;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\DayEventType;
use SmokeDetector\Domain\Day\DayFinalization;
use SmokeDetector\Domain\Day\DayFinalizer;
use SmokeDetector\Domain\Day\DayOutcome;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Level\LevelCalculator;
use SmokeDetector\Domain\Level\LevelRules;
use SmokeDetector\Domain\Plan\BaselineRules;
use SmokeDetector\Domain\Plan\PlanRules;
use SmokeDetector\Domain\Rules\RuleSet;
use SmokeDetector\Domain\Streak\StreakCalculator;
use SmokeDetector\Domain\Streak\StreakEventType;
use SmokeDetector\Domain\Streak\StreakRules;
use SmokeDetector\Domain\Streak\StreakState;
use SmokeDetector\Domain\Xp\XpAward;
use SmokeDetector\Domain\Xp\XpCalculator;
use SmokeDetector\Domain\Xp\XpRules;
use SmokeDetector\Tests\Unit\Domain\Rules\RuleTables;

/**
 * Finalizing one user-day (HLD §5.2 step 2): streaks, XP, level and the celebration events.
 *
 * Unless a test says otherwise it runs against the shipped rule table v1, so the numbers are those
 * of SRS Appendix A: check-in 10, under limit 20, smoke-free 40 on top, 7-day milestone 50,
 * level n at 50 × n × (n + 1) XP (L1 = 100, L2 = 300, L3 = 600, L4 = 1000, L5 = 1500, L6 = 2100).
 *
 * Events are written as "name" or "name:value", XP awards as [source, amount, detail].
 */
final class DayFinalizerTest extends TestCase
{
    private const int LIMIT = 8;

    private DayFinalizer $finalizer;

    protected function setUp(): void
    {
        $this->finalizer = new DayFinalizer(RuleTables::v1());
    }

    public function test_FR_GAME_01_checked_in_day_under_the_limit_earns_check_in_and_under_limit_xp(): void
    {
        $result = $this->finalizer->finalize(UserProgress::initial(), DayOutcome::checkedIn(count: 6, limit: 8));

        self::assertSame([['check_in', 10, null], ['under_limit', 20, null]], self::awards($result));
        self::assertSame(30, $result->xpEarned());
        self::assertSame(30, $result->totalXp);
        self::assertSame(0, $result->levelBefore);
        self::assertSame(0, $result->levelAfter);
    }

    public function test_FR_GAME_03_checked_in_day_under_the_limit_extends_both_streaks(): void
    {
        $before = new UserProgress(new StreakState(3, 5, 2, 4, 1), 500);

        $result = $this->finalizer->finalize($before, DayOutcome::checkedIn(count: 8, limit: 8));

        self::assertEquals(new StreakState(4, 5, 3, 4, 1), $result->streaks);
    }

    public function test_FR_GAME_06_checked_in_day_under_the_limit_is_celebrated_in_a_fixed_order(): void
    {
        $before = new UserProgress(new StreakState(3, 5, 2, 4, 1), 500);

        $result = $this->finalizer->finalize($before, DayOutcome::checkedIn(count: 6, limit: 8));

        self::assertSame(['checked_in', 'under_limit_day:3', 'streak_extended:4'], self::events($result));
    }

    public function test_FR_GAME_02_over_limit_day_earns_check_in_xp_only_and_the_check_in_streak_continues(): void
    {
        $before = new UserProgress(new StreakState(3, 5, 2, 4, 1), 500);

        $result = $this->finalizer->finalize($before, DayOutcome::checkedIn(count: 9, limit: 8));

        self::assertSame([['check_in', 10, null]], self::awards($result));
        self::assertSame(510, $result->totalXp);
        self::assertSame(4, $result->streaks->checkInCurrent);
        self::assertSame(1, $result->streaks->freezesHeld);
    }

    public function test_FR_GAME_03_over_limit_day_resets_the_under_limit_streak(): void
    {
        $before = new UserProgress(new StreakState(3, 5, 2, 4, 1), 500);

        $result = $this->finalizer->finalize($before, DayOutcome::checkedIn(count: 9, limit: 8));

        self::assertSame(0, $result->streaks->underLimitCurrent);
        self::assertSame(4, $result->streaks->underLimitLongest);
    }

    public function test_FR_LOG_06_over_limit_day_is_celebrated_for_the_check_in_and_emits_no_negative_event(): void
    {
        $before = new UserProgress(new StreakState(3, 5, 2, 4, 1), 500);

        $result = $this->finalizer->finalize($before, DayOutcome::checkedIn(count: 30, limit: 8));

        // The under-limit streak was lost, but logging honestly never triggers a "broken" event.
        self::assertSame(['checked_in', 'streak_extended:4'], self::events($result));
    }

    public function test_FR_GAME_01_smoke_free_day_earns_40_xp_on_top_of_check_in_and_under_limit_xp(): void
    {
        $result = $this->finalizer->finalize(UserProgress::initial(), DayOutcome::checkedIn(count: 0, limit: 8));

        self::assertSame(
            [['check_in', 10, null], ['under_limit', 20, null], ['smoke_free', 40, null]],
            self::awards($result),
        );
        self::assertSame(70, $result->totalXp);
        self::assertEquals(new StreakState(1, 1, 1, 1, 0), $result->streaks);
    }

    public function test_FR_GAME_06_smoke_free_day_is_celebrated_after_the_under_limit_day(): void
    {
        $result = $this->finalizer->finalize(UserProgress::initial(), DayOutcome::checkedIn(count: 0, limit: 8));

        self::assertSame(
            ['checked_in', 'under_limit_day:1', 'smoke_free_day', 'streak_extended:1'],
            self::events($result),
        );
    }

    public function test_FR_GAME_01_smoke_free_day_of_a_quitter_with_limit_zero_earns_the_full_70_xp(): void
    {
        $result = $this->finalizer->finalize(UserProgress::initial(), DayOutcome::checkedIn(count: 0, limit: 0));

        self::assertSame(70, $result->xpEarned());
    }

    public function test_FR_GAME_04_missed_day_with_a_freeze_uses_it_and_keeps_only_the_check_in_streak(): void
    {
        $before = new UserProgress(new StreakState(9, 12, 4, 6, 2), 500);

        $result = $this->finalizer->finalize($before, DayOutcome::missed(count: 3, limit: 8));

        // The freeze protects the check-in streak. The under-limit streak restarts, its best of 6 stays.
        self::assertEquals(new StreakState(9, 12, 0, 6, 1), $result->streaks);
        self::assertSame(['freeze_used:1'], self::events($result));
    }

    public function test_FR_GAME_03_frozen_day_with_a_running_under_limit_streak_emits_only_freeze_used(): void
    {
        $before = new UserProgress(new StreakState(14, 14, 7, 7, 1), 490);
        $frozenDay = DayOutcome::missed(count: 12, limit: 8);

        $result = $this->finalizer->finalize($before, $frozenDay);

        // The streak rules report the end of the under-limit streak for the history...
        $advance = new StreakCalculator(RuleTables::v1()->streak)->advance($before->streaks, $frozenDay);
        self::assertSame(7, $advance->event(StreakEventType::UnderLimitStreakBroken)?->value);
        // ...but the client hears about the freeze only: no negative event (HLD §6, FR-LOG-06 spirit).
        self::assertSame(['freeze_used:0'], self::events($result));
        self::assertEquals(new StreakState(14, 14, 0, 7, 0), $result->streaks);
        self::assertEquals(new UserProgress(new StreakState(14, 14, 0, 7, 0), 490), $result->progress());
        self::assertSame([], $result->xpAwards);
    }

    public function test_FR_GAME_03_first_under_limit_day_after_a_frozen_day_is_celebrated_as_day_1(): void
    {
        $before = new UserProgress(new StreakState(9, 12, 4, 6, 1), 500);

        $frozen = $this->finalizer->finalize($before, DayOutcome::missed(count: 3, limit: 8));
        $next = $this->finalizer->finalize($frozen->progress(), DayOutcome::checkedIn(count: 3, limit: 8));

        self::assertSame(['freeze_used:0'], self::events($frozen));
        self::assertSame(['checked_in', 'under_limit_day:1', 'streak_extended:10'], self::events($next));
        self::assertEquals(new StreakState(10, 12, 1, 6, 0), $next->streaks);
    }

    public function test_FR_GAME_03_heavy_day_ends_the_under_limit_streak_whether_it_is_checked_in_or_skipped(): void
    {
        // The loophole the freeze used to open: skipping the check-in kept the under-limit streak.
        $before = new UserProgress(new StreakState(9, 12, 4, 6, 2), 500);

        $honest = $this->finalizer->finalize($before, DayOutcome::checkedIn(count: 12, limit: 8));
        $skipped = $this->finalizer->finalize($before, DayOutcome::missed(count: 12, limit: 8));

        self::assertSame(0, $honest->streaks->underLimitCurrent);
        self::assertSame(0, $skipped->streaks->underLimitCurrent);
        // Honest: +10 XP, the check-in streak grows, both freezes kept. Skipped: nothing gained, one freeze gone.
        self::assertEquals(new StreakState(10, 12, 0, 6, 2), $honest->streaks);
        self::assertSame(510, $honest->totalXp);
        self::assertEquals(new StreakState(9, 12, 0, 6, 1), $skipped->streaks);
        self::assertSame(500, $skipped->totalXp);
    }

    public function test_FR_GAME_04_missed_day_without_a_freeze_resets_both_streaks(): void
    {
        $before = new UserProgress(new StreakState(9, 12, 4, 6, 0), 500);

        $result = $this->finalizer->finalize($before, DayOutcome::missed(count: 3, limit: 8));

        self::assertEquals(new StreakState(0, 12, 0, 6, 0), $result->streaks);
        // One event for the user: the under-limit streak ends with the check-in streak, not on its own.
        self::assertSame(['streak_broken:9'], self::events($result));
    }

    public function test_FR_GAME_01_missed_day_earns_no_xp_with_or_without_a_freeze(): void
    {
        $day = DayOutcome::missed(count: 0, limit: 8);

        $frozen = $this->finalizer->finalize(new UserProgress(new StreakState(9, 12, 4, 6, 2), 500), $day);
        $broken = $this->finalizer->finalize(new UserProgress(new StreakState(9, 12, 4, 6, 0), 500), $day);

        // Zero logs without a check-in are not a smoke-free day (SRS 1.4), so even they pay nothing.
        foreach ([$frozen, $broken] as $result) {
            self::assertSame([], $result->xpAwards);
            self::assertSame(0, $result->xpEarned());
            self::assertSame(500, $result->totalXp);
            self::assertSame(2, $result->levelBefore);
            self::assertSame(2, $result->levelAfter);
        }
    }

    public function test_FR_GAME_04_missed_day_of_a_user_without_a_streak_changes_nothing(): void
    {
        $before = new UserProgress(new StreakState(0, 12, 0, 6, 0), 500);

        $result = $this->finalizer->finalize($before, DayOutcome::missed(count: 3, limit: 8));

        self::assertEquals($before, $result->progress());
        self::assertSame([], $result->events);
    }

    public function test_FR_GAME_01_day_the_streak_hits_7_pays_the_milestone_earns_a_freeze_and_levels_up(): void
    {
        // Before: 6 days in a row, 260 XP = level 1 (L1 = 100, L2 = 300).
        $before = new UserProgress(new StreakState(6, 6, 6, 6, 0), 260);

        $result = $this->finalizer->finalize($before, DayOutcome::checkedIn(count: 6, limit: 8));

        // 10 check-in + 20 under limit + 50 first 7-day streak = 80. 260 + 80 = 340 = level 2.
        self::assertSame(
            [['check_in', 10, null], ['under_limit', 20, null], ['streak_milestone', 50, 7]],
            self::awards($result),
        );
        self::assertSame(80, $result->xpEarned());
        self::assertSame(340, $result->totalXp);
        self::assertSame(1, $result->levelBefore);
        self::assertSame(2, $result->levelAfter);
        self::assertEquals(new StreakState(7, 7, 7, 7, 1), $result->streaks);
        self::assertSame(
            [
                'checked_in',
                'under_limit_day:7',
                'streak_extended:7',
                'streak_milestone:7',
                'freeze_earned:1',
                'level_up:2',
            ],
            self::events($result),
        );
    }

    public function test_FR_GAME_01_rebuilt_streak_is_not_paid_for_the_same_milestone_twice(): void
    {
        // The longest streak was already 9 days, so reaching 7 again is no new personal best.
        $before = new UserProgress(new StreakState(6, 9, 6, 9, 0), 260);

        $result = $this->finalizer->finalize($before, DayOutcome::checkedIn(count: 6, limit: 8));

        self::assertSame([['check_in', 10, null], ['under_limit', 20, null]], self::awards($result));
        self::assertSame(290, $result->totalXp);
        // The freeze is earned again: it rewards the running streak, not the personal best.
        self::assertSame(
            ['checked_in', 'under_limit_day:7', 'streak_extended:7', 'freeze_earned:1'],
            self::events($result),
        );
    }

    public function test_FR_GAME_04_freeze_that_cannot_be_held_is_dropped_without_an_event(): void
    {
        $before = new UserProgress(new StreakState(20, 20, 0, 5, 2), 900);

        $result = $this->finalizer->finalize($before, DayOutcome::checkedIn(count: 9, limit: 8));

        self::assertSame(2, $result->streaks->freezesHeld);
        self::assertSame(['checked_in', 'streak_extended:21'], self::events($result));
    }

    public function test_FR_GAME_05_one_day_can_cross_several_levels_with_one_level_up_event_each(): void
    {
        // Day 365 of a streak for a user who has no XP yet (possible: XP is a ledger, streaks are counters).
        $before = new UserProgress(new StreakState(364, 364, 0, 0, 2), 0);

        $result = $this->finalizer->finalize($before, DayOutcome::checkedIn(count: 0, limit: 8));

        // 10 + 20 + 40 + 1500 for the first 365-day streak = 1570. L5 = 1500 ≤ 1570 < L6 = 2100.
        self::assertSame(1570, $result->totalXp);
        self::assertSame(0, $result->levelBefore);
        self::assertSame(5, $result->levelAfter);
        self::assertSame(
            [
                'checked_in',
                'under_limit_day:1',
                'smoke_free_day',
                'streak_extended:365',
                'streak_milestone:365',
                'level_up:1',
                'level_up:2',
                'level_up:3',
                'level_up:4',
                'level_up:5',
            ],
            self::events($result),
        );
    }

    public function test_FR_GAME_05_level_up_is_reported_exactly_when_the_threshold_is_reached(): void
    {
        $day = DayOutcome::checkedIn(count: 6, limit: 8);
        $streaks = new StreakState(1, 1, 1, 1, 0);

        // 30 XP for the day: 69 → 99 stays on level 0, 70 → 100 reaches level 1.
        $below = $this->finalizer->finalize(new UserProgress($streaks, 69), $day);
        $exact = $this->finalizer->finalize(new UserProgress($streaks, 70), $day);

        self::assertSame(0, $below->levelAfter);
        self::assertNotContains('level_up:1', self::events($below));
        self::assertSame(1, $exact->levelAfter);
        self::assertSame('level_up:1', self::events($exact)[3]);
    }

    /**
     * Thirty days of one user, limit 8 throughout. Hand calculation:
     *
     *  day    what happened        XP of the day        total  check-in  under-limit  freezes  note
     *  1-6    6 sessions           6 × (10+20) = 180      180     6         6           0      level 1 on day 4 (120)
     *  7      10 sessions (over)   10 + 50 milestone      240     7         0           1      first 7-day streak, freeze earned
     *  8      no check-in          0                      240     7         0           0      freeze used
     *  9-13   5 sessions           5 × 30 = 150           390    12         5           0      level 2 on day 10 (300)
     *  14     0 sessions           10+20+40 = 70          460    13         6           0
     *  15     8 sessions (= limit) 30                     490    14         7           1      freeze earned at 14
     *  16     no check-in          0                      490    14         0           0      freeze used, under-limit streak ends (was 7)
     *  17     no check-in          0                      490     0         0           0      streak broken (was 14)
     *  18-24  4 sessions           7 × 30 = 210           700     7         7           1      level 3 on day 21 (610). 7 again: freeze, no milestone
     *  25     9 sessions (over)    10                     710     8         0           1
     *  26-27  0 sessions           2 × 70 = 140           850    10         2           1
     *  28     no check-in          0                      850    10         0           0      freeze used, under-limit streak ends (was 2)
     *  29-30  3 sessions           2 × 30 = 60            910    12         2           0
     *
     * A freeze protects the check-in streak only: on the frozen days 8, 16 and 28 the under-limit
     * streak is 0 afterwards (on day 8 it already was). XP does not depend on the length of the
     * under-limit streak, so the XP columns are the same with or without that rule.
     *
     * Cross-check: 26 check-ins × 10 + 24 under-limit days × 20 + 3 smoke-free days × 40 + 50 = 910.
     * 910 XP is level 3 (L3 = 600, L4 = 1000). Longest check-in streak 14 (days 15-16), longest
     * under-limit streak 7 (reached on day 15 and again on day 24).
     */
    public function test_FR_GAME_01_thirty_day_sequence_matches_the_hand_calculation(): void
    {
        $results = self::replay($this->finalizer, self::thirtyDays());
        $last = $results[29];

        self::assertSame(910, $last->totalXp);
        self::assertSame(3, $last->levelAfter);
        self::assertEquals(new StreakState(12, 14, 2, 7, 0), $last->streaks);

        $totals = array_map(static fn (DayFinalization $result): int => $result->totalXp, $results);
        self::assertSame(
            [
                30, 60, 90, 120, 150, 180,
                240,
                240,
                270, 300, 330, 360, 390,
                460,
                490,
                490,
                490,
                520, 550, 580, 610, 640, 670, 700,
                710,
                780, 850,
                850,
                880, 910,
            ],
            $totals,
        );
    }

    public function test_FR_GAME_03_thirty_day_sequence_matches_the_hand_calculated_streaks(): void
    {
        $results = self::replay($this->finalizer, self::thirtyDays());

        // The check-in and under-limit columns of the table above, one entry per day.
        self::assertSame(
            [
                1, 2, 3, 4, 5, 6,
                7,
                7,
                8, 9, 10, 11, 12,
                13,
                14,
                14,
                0,
                1, 2, 3, 4, 5, 6, 7,
                8,
                9, 10,
                10,
                11, 12,
            ],
            array_map(static fn (DayFinalization $result): int => $result->streaks->checkInCurrent, $results),
        );
        self::assertSame(
            [
                1, 2, 3, 4, 5, 6,
                0,
                0,
                1, 2, 3, 4, 5,
                6,
                7,
                0,
                0,
                1, 2, 3, 4, 5, 6, 7,
                0,
                1, 2,
                0,
                1, 2,
            ],
            array_map(static fn (DayFinalization $result): int => $result->streaks->underLimitCurrent, $results),
        );
        self::assertSame(
            [
                0, 0, 0, 0, 0, 0,
                1,
                0,
                0, 0, 0, 0, 0,
                0,
                1,
                0,
                0,
                0, 0, 0, 0, 0, 0, 1,
                1,
                1, 1,
                0,
                0, 0,
            ],
            array_map(static fn (DayFinalization $result): int => $result->streaks->freezesHeld, $results),
        );
        // The frozen days 16 and 28 keep the personal bests.
        self::assertEquals(new StreakState(14, 14, 0, 7, 0), $results[15]->streaks);
        self::assertEquals(new StreakState(10, 14, 0, 7, 0), $results[27]->streaks);
    }

    public function test_FR_GAME_06_thirty_day_sequence_emits_the_expected_events(): void
    {
        $results = self::replay($this->finalizer, self::thirtyDays());

        $counts = [];
        foreach ($results as $result) {
            foreach ($result->events as $event) {
                $counts[$event->type->value] = ($counts[$event->type->value] ?? 0) + 1;
            }
        }

        self::assertEquals(
            [
                'checked_in' => 26,
                'under_limit_day' => 24,
                'smoke_free_day' => 3,
                'streak_extended' => 26,
                'streak_milestone' => 1,
                'freeze_earned' => 3,
                'freeze_used' => 3,
                'streak_broken' => 1,
                'level_up' => 3,
            ],
            $counts,
        );

        // Index = day − 1.
        self::assertSame(['checked_in', 'under_limit_day:4', 'streak_extended:4', 'level_up:1'], self::events($results[3]));
        self::assertSame(['checked_in', 'streak_extended:7', 'streak_milestone:7', 'freeze_earned:1'], self::events($results[6]));
        self::assertSame(['freeze_used:0'], self::events($results[7]));
        self::assertSame(['checked_in', 'under_limit_day:2', 'streak_extended:9', 'level_up:2'], self::events($results[9]));
        self::assertSame(['checked_in', 'under_limit_day:7', 'streak_extended:14', 'freeze_earned:1'], self::events($results[14]));
        self::assertSame(['freeze_used:0'], self::events($results[15]));
        self::assertSame(['streak_broken:14'], self::events($results[16]));
        self::assertSame(['checked_in', 'under_limit_day:4', 'streak_extended:4', 'level_up:3'], self::events($results[20]));
        self::assertSame(['checked_in', 'under_limit_day:7', 'streak_extended:7', 'freeze_earned:1'], self::events($results[23]));
        self::assertSame(['checked_in', 'streak_extended:8'], self::events($results[24]));
        self::assertSame(['freeze_used:0'], self::events($results[27]));
        // After the frozen day 28 the under-limit streak starts again at 1.
        self::assertSame(['checked_in', 'under_limit_day:1', 'streak_extended:11'], self::events($results[28]));
        self::assertSame(['checked_in', 'under_limit_day:2', 'streak_extended:12'], self::events($results[29]));
    }

    public function test_FR_GAME_01_every_day_of_the_sequence_adds_exactly_its_awards_to_the_total(): void
    {
        $total = 0;
        $level = 0;
        foreach (self::replay($this->finalizer, self::thirtyDays()) as $result) {
            self::assertSame($total + $result->xpEarned(), $result->totalXp);
            self::assertSame($level, $result->levelBefore);
            $total = $result->totalXp;
            $level = $result->levelAfter;
        }
    }

    public function test_FR_GAME_01_custom_rule_set_changes_the_outcome_of_the_same_day(): void
    {
        $custom = new DayFinalizer(new RuleSet(
            'pilot-b',
            new PlanRules(),
            new BaselineRules(),
            new StreakRules(freezeInterval: 3, maxFreezesHeld: 1, milestoneThresholds: [3]),
            new XpRules(checkIn: 5, underLimit: 5, smokeFree: 0, streakMilestones: [3 => 100]),
            // Level n at 10 × n × (n + 1): L1 = 20, L2 = 60, L3 = 120, L4 = 200.
            new LevelRules(factor: 10),
            new CompetitionRules(),
        ));
        $before = new UserProgress(new StreakState(2, 2, 2, 2, 0), 15);
        $day = DayOutcome::checkedIn(count: 0, limit: 8);

        $v1 = $this->finalizer->finalize($before, $day);
        $pilot = $custom->finalize($before, $day);

        // v1: 10 + 20 + 40 = 70, no milestone at 3 days, no freeze, 85 XP is still level 0.
        self::assertSame(85, $v1->totalXp);
        self::assertSame(0, $v1->levelAfter);
        self::assertSame(0, $v1->streaks->freezesHeld);
        self::assertSame(
            ['checked_in', 'under_limit_day:3', 'smoke_free_day', 'streak_extended:3'],
            self::events($v1),
        );

        // Custom: 5 + 5 + 100 for the 3-day milestone = 110 (smoke-free XP is switched off). 125 XP is level 3.
        self::assertSame(
            [['check_in', 5, null], ['under_limit', 5, null], ['streak_milestone', 100, 3]],
            self::awards($pilot),
        );
        self::assertSame(125, $pilot->totalXp);
        self::assertSame(0, $pilot->levelBefore);
        self::assertSame(3, $pilot->levelAfter);
        self::assertSame(1, $pilot->streaks->freezesHeld);
        self::assertSame(
            [
                'checked_in',
                'under_limit_day:3',
                'smoke_free_day',
                'streak_extended:3',
                'streak_milestone:3',
                'freeze_earned:1',
                'level_up:1',
                'level_up:2',
                'level_up:3',
            ],
            self::events($pilot),
        );
    }

    public function test_FR_GAME_01_milestone_with_zero_xp_is_still_celebrated_but_pays_nothing(): void
    {
        $finalizer = new DayFinalizer(new RuleSet(
            'no-milestone-xp',
            new PlanRules(),
            new BaselineRules(),
            new StreakRules(milestoneThresholds: [7]),
            new XpRules(streakMilestones: [7 => 0]),
            new LevelRules(),
            new CompetitionRules(),
        ));

        $result = $finalizer->finalize(
            new UserProgress(new StreakState(6, 6, 6, 6, 0), 0),
            DayOutcome::checkedIn(count: 6, limit: 8),
        );

        self::assertSame([['check_in', 10, null], ['under_limit', 20, null]], self::awards($result));
        self::assertContains('streak_milestone:7', self::events($result));
    }

    public function test_NFR_04_replaying_the_same_sequence_gives_identical_results(): void
    {
        $first = self::replay(new DayFinalizer(RuleTables::v1()), self::thirtyDays());
        $second = self::replay(new DayFinalizer(RuleTables::v1()), self::thirtyDays());

        self::assertEquals($first, $second);
        // assertEquals compares loosely. The serialized form also pins every type and the order.
        self::assertSame(serialize($first), serialize($second));
        // The end of the hand calculation of the thirty days (see the FR_GAME_01 test above), so
        // that a finalizer that is wrong in the same way every time fails too.
        self::assertSame([910, 3], [$first[29]->totalXp, $first[29]->levelAfter]);
        self::assertEquals(new StreakState(12, 14, 2, 7, 0), $first[29]->streaks);
    }

    public function test_NFR_04_finalizing_the_same_day_twice_with_one_finalizer_gives_identical_results(): void
    {
        $before = new UserProgress(new StreakState(6, 6, 6, 6, 0), 260);
        $day = DayOutcome::checkedIn(count: 6, limit: 8);

        $result = $this->finalizer->finalize($before, $day);
        // Worked out by hand: day 7 of both streaks, the 7-day milestone (50 XP) and the first freeze;
        // 260 + 10 + 20 + 50 = 340 XP crosses level 2 (300 XP).
        self::assertEquals(new StreakState(7, 7, 7, 7, 1), $result->streaks);
        self::assertSame([['check_in', 10, null], ['under_limit', 20, null], ['streak_milestone', 50, 7]], self::awards($result));
        self::assertSame([340, 1, 2], [$result->totalXp, $result->levelBefore, $result->levelAfter]);
        self::assertSame(
            ['checked_in', 'under_limit_day:7', 'streak_extended:7', 'streak_milestone:7', 'freeze_earned:1', 'level_up:2'],
            self::events($result),
        );

        // No hidden state: the first call must not change what the second one returns.
        self::assertSame(
            serialize($this->finalizer->finalize($before, $day)),
            serialize($this->finalizer->finalize($before, $day)),
        );
        self::assertEquals(new UserProgress(new StreakState(6, 6, 6, 6, 0), 260), $before);
    }

    /**
     * The finalizer only combines the streak, XP and level rules. Over a grid of situations its result
     * must be exactly what those rules say on their own, so the two can never drift apart.
     */
    public function test_FR_GAME_02_result_never_differs_from_the_streak_xp_and_level_rules_it_combines(): void
    {
        $rules = RuleTables::v1();
        $streakRules = new StreakCalculator($rules->streak);
        $xpRules = new XpCalculator($rules->xp);
        $levelRules = new LevelCalculator($rules->level);
        $days = [
            DayOutcome::missed(count: 0, limit: 8),
            DayOutcome::checkedIn(count: 0, limit: 8),
            DayOutcome::checkedIn(count: 8, limit: 8),
            DayOutcome::checkedIn(count: 9, limit: 8),
        ];

        $checked = 0;
        foreach ($days as $day) {
            for ($streak = 0; $streak <= 30; $streak++) {
                for ($freezes = 0; $freezes <= 2; $freezes++) {
                    foreach ([0, 95, 290, 5990] as $xpBefore) {
                        $before = new UserProgress(new StreakState($streak, $streak, $streak, $streak, $freezes), $xpBefore);

                        $result = $this->finalizer->finalize($before, $day);

                        $advance = $streakRules->advance($before->streaks, $day);
                        $milestone = $advance->event(StreakEventType::CheckInStreakMilestoneReached);
                        $expectedXp = XpCalculator::total($xpRules->forDay($day))
                            + ($milestone === null ? 0 : ($rules->xp->streakMilestones[$milestone->value] ?? 0));

                        self::assertEquals($advance->state, $result->streaks);
                        self::assertSame($expectedXp, $result->xpEarned());
                        self::assertSame($xpBefore + $expectedXp, $result->totalXp);
                        self::assertSame($levelRules->levelFor($xpBefore), $result->levelBefore);
                        self::assertSame($levelRules->levelFor($result->totalXp), $result->levelAfter);
                        self::assertCount(
                            $result->levelAfter - $result->levelBefore,
                            $result->eventsOfType(DayEventType::LevelUp),
                        );
                        self::assertCount($day->checkedIn ? 1 : 0, $result->eventsOfType(DayEventType::CheckedIn));
                        self::assertCount($day->checkedIn ? 1 : 0, $result->eventsOfType(DayEventType::StreakExtended));
                        if (!$day->checkedIn) {
                            self::assertSame([], $result->xpAwards);
                            // No day without a check-in keeps an under-limit streak, frozen or not
                            // (FR-GAME-03), and the client is told about the check-in streak only.
                            self::assertSame(0, $result->streaks->underLimitCurrent);
                            self::assertSame(
                                match (true) {
                                    $streak === 0 => [],
                                    $freezes > 0 => ['freeze_used:' . ($freezes - 1)],
                                    default => ["streak_broken:{$streak}"],
                                },
                                self::events($result),
                            );
                        }
                        $checked++;
                    }
                }
            }
        }

        // 4 kinds of day × 31 streak lengths × 3 freeze counts × 4 XP totals.
        self::assertSame(1488, $checked);
    }

    public function test_FR_GAME_04_progress_holding_more_freezes_than_the_rules_allow_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->finalizer->finalize(
            new UserProgress(new StreakState(9, 9, 0, 0, 3), 0),
            DayOutcome::checkedIn(count: 6, limit: 8),
        );
    }

    public function test_FR_GAME_01_xp_total_beyond_the_integer_range_is_rejected(): void
    {
        // A float total would silently lose single XP points, so this fails loudly instead.
        $this->expectException(InvalidArgumentException::class);

        $this->finalizer->finalize(
            new UserProgress(StreakState::initial(), PHP_INT_MAX - 29),
            DayOutcome::checkedIn(count: 6, limit: 8),
        );
    }

    public function test_FR_GAME_01_xp_total_of_exactly_the_largest_integer_is_still_exact(): void
    {
        $result = $this->finalizer->finalize(
            new UserProgress(StreakState::initial(), PHP_INT_MAX - 30),
            DayOutcome::checkedIn(count: 6, limit: 8),
        );

        self::assertSame(PHP_INT_MAX, $result->totalXp);
    }

    /**
     * The sequence of the hand calculation above, day 1 first.
     *
     * @return list<DayOutcome>
     */
    private static function thirtyDays(): array
    {
        $missed = DayOutcome::missed(count: 2, limit: self::LIMIT);

        return [
            ...self::days(6, 6),
            ...self::days(1, 10),
            $missed,
            ...self::days(5, 5),
            ...self::days(1, 0),
            ...self::days(1, 8),
            $missed,
            $missed,
            ...self::days(7, 4),
            ...self::days(1, 9),
            ...self::days(2, 0),
            $missed,
            ...self::days(2, 3),
        ];
    }

    /**
     * @return list<DayOutcome>
     */
    private static function days(int $howMany, int $count): array
    {
        return array_fill(0, $howMany, DayOutcome::checkedIn($count, self::LIMIT));
    }

    /**
     * Finalizes the days in order for a new user, feeding each result into the next day.
     *
     * @param list<DayOutcome> $days
     *
     * @return list<DayFinalization>
     */
    private static function replay(DayFinalizer $finalizer, array $days): array
    {
        $progress = UserProgress::initial();
        $results = [];
        foreach ($days as $day) {
            $result = $finalizer->finalize($progress, $day);
            $results[] = $result;
            $progress = $result->progress();
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private static function events(DayFinalization $result): array
    {
        return array_map(
            static fn (DayEvent $event): string => $event->value === null
                ? $event->type->value
                : "{$event->type->value}:{$event->value}",
            $result->events,
        );
    }

    /**
     * @return list<array{string, int, int|null}>
     */
    private static function awards(DayFinalization $result): array
    {
        return array_map(
            static fn (XpAward $award): array => [$award->source->value, $award->amount, $award->detail],
            $result->xpAwards,
        );
    }
}
