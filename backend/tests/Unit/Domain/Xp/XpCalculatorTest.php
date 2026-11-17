<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Xp;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Day\DayOutcome;
use SmokeDetector\Domain\Xp\DuelResult;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Domain\Xp\XpAward;
use SmokeDetector\Domain\Xp\XpCalculator;
use SmokeDetector\Domain\Xp\XpRules;
use SmokeDetector\Domain\Xp\XpSource;

/**
 * XP per event, SRS FR-GAME-01 with the default values of Appendix A.
 */
final class XpCalculatorTest extends TestCase
{
    private XpCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new XpCalculator(new XpRules());
    }

    // ---- Day XP -------------------------------------------------------------------------------

    public function test_FR_GAME_01_checked_in_day_over_the_limit_earns_only_the_check_in_xp(): void
    {
        $awards = $this->calculator->forDay(DayOutcome::checkedIn(count: 6, limit: 5));

        self::assertEquals([new XpAward(XpSource::CheckIn, 10)], $awards);
    }

    public function test_FR_GAME_01_checked_in_day_at_the_limit_earns_check_in_and_under_limit_xp(): void
    {
        $awards = $this->calculator->forDay(DayOutcome::checkedIn(count: 5, limit: 5));

        self::assertEquals(
            [new XpAward(XpSource::CheckIn, 10), new XpAward(XpSource::UnderLimit, 20)],
            $awards,
        );
        self::assertSame(30, XpCalculator::total($awards));
    }

    public function test_FR_GAME_01_smoke_free_day_earns_40_xp_on_top_of_check_in_and_under_limit(): void
    {
        $awards = $this->calculator->forDay(DayOutcome::checkedIn(count: 0, limit: 5));

        self::assertEquals(
            [
                new XpAward(XpSource::CheckIn, 10),
                new XpAward(XpSource::UnderLimit, 20),
                new XpAward(XpSource::SmokeFree, 40),
            ],
            $awards,
        );
        self::assertSame(70, XpCalculator::total($awards));
    }

    public function test_FR_GAME_01_smoke_free_day_with_a_limit_of_zero_earns_the_full_70_xp(): void
    {
        $awards = $this->calculator->forDay(DayOutcome::checkedIn(count: 0, limit: 0));

        self::assertSame(70, XpCalculator::total($awards));
    }

    public function test_FR_GAME_01_day_without_check_in_earns_nothing(): void
    {
        self::assertSame([], $this->calculator->forDay(DayOutcome::missed(count: 3, limit: 5)));
    }

    public function test_FR_GAME_01_day_without_check_in_and_without_logs_earns_nothing(): void
    {
        // Zero logs without a check-in is not a smoke-free day (SRS 1.4), so it must not pay like one.
        self::assertSame([], $this->calculator->forDay(DayOutcome::missed(count: 0, limit: 5)));
    }

    public function test_FR_GAME_01_day_awards_with_an_amount_of_zero_are_not_emitted(): void
    {
        $calculator = new XpCalculator(new XpRules(underLimit: 0));

        $awards = $calculator->forDay(DayOutcome::checkedIn(count: 0, limit: 5));

        self::assertEquals(
            [new XpAward(XpSource::CheckIn, 10), new XpAward(XpSource::SmokeFree, 40)],
            $awards,
        );
    }

    public function test_FR_GAME_01_day_earns_nothing_when_every_day_value_is_zero(): void
    {
        $calculator = new XpCalculator(new XpRules(checkIn: 0, underLimit: 0, smokeFree: 0));

        self::assertSame([], $calculator->forDay(DayOutcome::checkedIn(count: 0, limit: 5)));
    }

    // ---- Streak milestones --------------------------------------------------------------------

    #[DataProvider('streakMilestones')]
    public function test_FR_GAME_01_streak_milestone_earns_the_xp_of_appendix_a(int $threshold, int $xp): void
    {
        self::assertEquals(
            new XpAward(XpSource::StreakMilestone, $xp, $threshold),
            $this->calculator->forStreakMilestone($threshold),
        );
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function streakMilestones(): iterable
    {
        yield '7 days' => [7, 50];
        yield '30 days' => [30, 150];
        yield '100 days' => [100, 500];
        yield '365 days' => [365, 1500];
    }

    #[DataProvider('streakLengthsWithoutMilestone')]
    public function test_FR_GAME_01_streak_length_that_is_not_a_milestone_earns_nothing(int $length): void
    {
        self::assertNull($this->calculator->forStreakMilestone($length));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function streakLengthsWithoutMilestone(): iterable
    {
        foreach ([0, 1, 6, 8, 14, 29, 31, 99, 101, 364, 366, 730] as $length) {
            yield "{$length} days" => [$length];
        }
    }

    public function test_FR_GAME_01_negative_streak_length_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->forStreakMilestone(-1);
    }

    // ---- Duels --------------------------------------------------------------------------------

    #[DataProvider('duelResults')]
    public function test_FR_GAME_01_duel_result_earns_the_xp_of_appendix_a(DuelResult $result, int $xp): void
    {
        self::assertEquals(new XpAward(XpSource::Duel, $xp), $this->calculator->forDuel($result));
    }

    /**
     * @return iterable<string, array{DuelResult, int}>
     */
    public static function duelResults(): iterable
    {
        yield 'won' => [DuelResult::Won, 100];
        yield 'draw' => [DuelResult::Draw, 50];
        yield 'lost but completed' => [DuelResult::Lost, 20];
    }

    public function test_FR_GAME_01_forfeited_duel_earns_nothing(): void
    {
        self::assertNull($this->calculator->forDuel(DuelResult::Forfeited));
    }

    // ---- Leagues ------------------------------------------------------------------------------

    #[DataProvider('leagueRanks')]
    public function test_FR_GAME_01_league_rank_earns_the_xp_of_appendix_a_times_the_period_multiplier(
        int $rank,
        LeaguePeriod $period,
        int $xp,
    ): void {
        self::assertEquals(
            new XpAward(XpSource::League, $xp, $rank),
            $this->calculator->forLeague($rank, $period, participants: 5),
        );
    }

    /**
     * @return iterable<string, array{int, LeaguePeriod, int}>
     */
    public static function leagueRanks(): iterable
    {
        yield 'week rank 1' => [1, LeaguePeriod::Week, 150];
        yield 'week rank 2' => [2, LeaguePeriod::Week, 100];
        yield 'week rank 3' => [3, LeaguePeriod::Week, 50];
        yield 'month rank 1' => [1, LeaguePeriod::Month, 300];
        yield 'month rank 2' => [2, LeaguePeriod::Month, 200];
        yield 'month rank 3' => [3, LeaguePeriod::Month, 100];
        yield 'quarter rank 1' => [1, LeaguePeriod::Quarter, 450];
        yield 'quarter rank 2' => [2, LeaguePeriod::Quarter, 300];
        yield 'quarter rank 3' => [3, LeaguePeriod::Quarter, 150];
        yield 'half-year rank 1' => [1, LeaguePeriod::HalfYear, 600];
        yield 'half-year rank 2' => [2, LeaguePeriod::HalfYear, 400];
        yield 'half-year rank 3' => [3, LeaguePeriod::HalfYear, 200];
    }

    public function test_FR_GAME_01_league_rank_4_and_below_earns_nothing(): void
    {
        self::assertNull($this->calculator->forLeague(4, LeaguePeriod::Week, participants: 10));
        self::assertNull($this->calculator->forLeague(10, LeaguePeriod::HalfYear, participants: 10));
    }

    public function test_FR_GAME_01_league_with_fewer_than_3_participants_earns_nothing(): void
    {
        self::assertNull($this->calculator->forLeague(1, LeaguePeriod::Week, participants: 1));
        self::assertNull($this->calculator->forLeague(1, LeaguePeriod::Month, participants: 2));
        self::assertNull($this->calculator->forLeague(2, LeaguePeriod::Month, participants: 2));
    }

    public function test_FR_GAME_01_league_with_exactly_3_participants_pays_all_three_ranks(): void
    {
        self::assertSame(150, $this->calculator->forLeague(1, LeaguePeriod::Week, participants: 3)?->amount);
        self::assertSame(100, $this->calculator->forLeague(2, LeaguePeriod::Week, participants: 3)?->amount);
        self::assertSame(50, $this->calculator->forLeague(3, LeaguePeriod::Week, participants: 3)?->amount);
    }

    public function test_FR_GAME_01_league_rank_below_1_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->forLeague(0, LeaguePeriod::Week, participants: 5);
    }

    public function test_FR_GAME_01_league_rank_beyond_the_number_of_participants_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->forLeague(4, LeaguePeriod::Week, participants: 3);
    }

    // ---- Reactions ----------------------------------------------------------------------------

    #[DataProvider('reactionXpBelowTheCap')]
    public function test_FR_GAME_01_reaction_earns_2_xp_while_the_day_is_below_the_cap(int $alreadyEarned): void
    {
        self::assertEquals(
            new XpAward(XpSource::Reaction, 2),
            $this->calculator->forReaction($alreadyEarned),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function reactionXpBelowTheCap(): iterable
    {
        foreach ([0, 2, 4, 6, 8] as $alreadyEarned) {
            yield "{$alreadyEarned} XP earned today" => [$alreadyEarned];
        }
    }

    public function test_FR_GAME_01_reaction_that_would_cross_the_cap_earns_only_the_remainder(): void
    {
        // 9 + 2 would be 11. The user still gets the 1 XP that fits under the cap of 10.
        self::assertEquals(new XpAward(XpSource::Reaction, 1), $this->calculator->forReaction(9));
    }

    public function test_FR_GAME_01_reaction_earns_nothing_once_the_daily_cap_is_reached(): void
    {
        self::assertNull($this->calculator->forReaction(10));
    }

    public function test_FR_GAME_01_reaction_earns_nothing_when_the_day_is_already_above_the_cap(): void
    {
        // Can happen when the cap is lowered during a day. It is not an error, there is just nothing left.
        self::assertNull($this->calculator->forReaction(11));
        self::assertNull($this->calculator->forReaction(1000));
    }

    public function test_FR_GAME_01_reactions_of_one_day_never_earn_more_than_10_xp_in_total(): void
    {
        $earned = 0;
        $paidReactions = 0;
        for ($reaction = 1; $reaction <= 20; $reaction++) {
            $award = $this->calculator->forReaction($earned);
            if ($award !== null) {
                $earned += $award->amount;
                $paidReactions++;
            }
        }

        self::assertSame(10, $earned);
        self::assertSame(5, $paidReactions);
    }

    public function test_FR_GAME_01_negative_reaction_xp_earned_today_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->forReaction(-1);
    }

    // ---- Sum ----------------------------------------------------------------------------------

    public function test_FR_GAME_01_total_of_no_awards_is_zero(): void
    {
        self::assertSame(0, XpCalculator::total([]));
    }

    public function test_FR_GAME_01_total_adds_up_awards_from_different_sources(): void
    {
        $awards = [
            new XpAward(XpSource::CheckIn, 10),
            new XpAward(XpSource::StreakMilestone, 50, 7),
            new XpAward(XpSource::Duel, 100),
            new XpAward(XpSource::Reaction, 2),
        ];

        self::assertSame(162, XpCalculator::total($awards));
    }

    public function test_FR_GAME_01_total_of_exactly_the_largest_integer_is_still_exact(): void
    {
        $awards = [new XpAward(XpSource::Duel, PHP_INT_MAX - 1), new XpAward(XpSource::Reaction, 1)];

        self::assertSame(PHP_INT_MAX, XpCalculator::total($awards));
    }

    public function test_FR_GAME_01_total_beyond_the_integer_range_is_rejected(): void
    {
        // A float total would silently lose single XP points, so this fails loudly instead.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The XP awards are too large to be added up.');

        XpCalculator::total([new XpAward(XpSource::Duel, PHP_INT_MAX), new XpAward(XpSource::Duel, 1)]);
    }

    public function test_FR_GAME_01_total_beyond_the_integer_range_is_rejected_at_any_position(): void
    {
        $this->expectException(InvalidArgumentException::class);

        XpCalculator::total([
            new XpAward(XpSource::CheckIn, 10),
            new XpAward(XpSource::Duel, PHP_INT_MAX - 10),
            new XpAward(XpSource::Reaction, 1),
        ]);
    }

    public function test_FR_GAME_01_largest_numbers_a_rule_table_can_hold_still_give_exact_awards(): void
    {
        $max = XpRules::MAX_VALUE;
        $calculator = new XpCalculator(new XpRules(
            checkIn: $max,
            underLimit: $max,
            smokeFree: $max,
            streakMilestones: [7 => $max],
            leagueRankXp: [1 => $max],
            leagueHalfYearMultiplier: $max,
        ));

        $day = $calculator->forDay(DayOutcome::checkedIn(count: 0, limit: 5));

        self::assertSame(3_000_000, XpCalculator::total($day));
        self::assertSame(1_000_000, $calculator->forStreakMilestone(7)?->amount);
        self::assertSame(1_000_000_000_000, $calculator->forLeague(1, LeaguePeriod::HalfYear, 5)?->amount);
    }

    // ---- The table is config, not code --------------------------------------------------------

    public function test_FR_GAME_01_custom_rule_table_changes_every_result(): void
    {
        $calculator = new XpCalculator(new XpRules(
            checkIn: 1,
            underLimit: 2,
            smokeFree: 3,
            streakMilestones: [5 => 11, 7 => 12],
            duelWon: 21,
            duelDraw: 22,
            duelLost: 23,
            duelForfeited: 24,
            leagueRankXp: [1 => 31, 2 => 32, 3 => 33, 4 => 34],
            leagueMinParticipants: 2,
            leagueWeekMultiplier: 5,
            leagueMonthMultiplier: 6,
            leagueQuarterMultiplier: 7,
            leagueHalfYearMultiplier: 8,
            reaction: 4,
            reactionDailyCap: 6,
        ));

        self::assertEquals(
            [
                new XpAward(XpSource::CheckIn, 1),
                new XpAward(XpSource::UnderLimit, 2),
                new XpAward(XpSource::SmokeFree, 3),
            ],
            $calculator->forDay(DayOutcome::checkedIn(count: 0, limit: 5)),
        );

        self::assertSame(11, $calculator->forStreakMilestone(5)?->amount);
        self::assertSame(12, $calculator->forStreakMilestone(7)?->amount);
        self::assertNull($calculator->forStreakMilestone(30));

        self::assertSame(21, $calculator->forDuel(DuelResult::Won)?->amount);
        self::assertSame(22, $calculator->forDuel(DuelResult::Draw)?->amount);
        self::assertSame(23, $calculator->forDuel(DuelResult::Lost)?->amount);
        self::assertSame(24, $calculator->forDuel(DuelResult::Forfeited)?->amount);

        self::assertSame(31 * 5, $calculator->forLeague(1, LeaguePeriod::Week, 2)?->amount);
        self::assertSame(32 * 6, $calculator->forLeague(2, LeaguePeriod::Month, 2)?->amount);
        self::assertSame(33 * 7, $calculator->forLeague(3, LeaguePeriod::Quarter, 4)?->amount);
        self::assertSame(34 * 8, $calculator->forLeague(4, LeaguePeriod::HalfYear, 4)?->amount);
        self::assertNull($calculator->forLeague(1, LeaguePeriod::Week, 1));

        self::assertSame(4, $calculator->forReaction(0)?->amount);
        self::assertSame(2, $calculator->forReaction(4)?->amount);
        self::assertNull($calculator->forReaction(6));
    }

    public function test_FR_GAME_01_league_period_with_a_multiplier_of_zero_earns_nothing(): void
    {
        $calculator = new XpCalculator(new XpRules(leagueWeekMultiplier: 0));

        self::assertNull($calculator->forLeague(1, LeaguePeriod::Week, participants: 5));
    }

    // ---- Determinism --------------------------------------------------------------------------

    public function test_NFR_04_same_inputs_and_same_rules_always_give_identical_awards(): void
    {
        $first = self::everyAward(new XpCalculator(new XpRules()));

        for ($run = 0; $run < 5; $run++) {
            self::assertEquals($first, self::everyAward($this->calculator));
            self::assertEquals($first, self::everyAward(new XpCalculator(new XpRules())));
        }
    }

    /**
     * @return list<XpAward|null>
     */
    private static function everyAward(XpCalculator $calculator): array
    {
        $awards = [
            ...$calculator->forDay(DayOutcome::checkedIn(count: 0, limit: 4)),
            ...$calculator->forDay(DayOutcome::checkedIn(count: 9, limit: 4)),
            ...$calculator->forDay(DayOutcome::missed(count: 0, limit: 4)),
        ];
        foreach ([1, 7, 30, 100, 365] as $length) {
            $awards[] = $calculator->forStreakMilestone($length);
        }
        foreach (DuelResult::cases() as $result) {
            $awards[] = $calculator->forDuel($result);
        }
        foreach (LeaguePeriod::cases() as $period) {
            foreach ([1, 2, 3, 4] as $rank) {
                $awards[] = $calculator->forLeague($rank, $period, participants: 6);
            }
        }
        foreach ([0, 8, 9, 10] as $alreadyEarned) {
            $awards[] = $calculator->forReaction($alreadyEarned);
        }

        return $awards;
    }
}
