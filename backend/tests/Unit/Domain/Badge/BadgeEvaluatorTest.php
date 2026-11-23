<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Badge;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Badge\BadgeEvaluator;
use SmokeDetector\Domain\Day\DayOutcome;
use SmokeDetector\Domain\Streak\StreakState;
use SmokeDetector\Domain\Xp\LeaguePeriod;

/**
 * Which badges a set of facts justifies (SRS FR-GAME-08, Appendix B, HLD §4.10).
 *
 * The evaluator answers "which badges do these facts justify", not "which are new": it names a badge
 * every time its condition holds, and the storage keeps the first award. So nothing is lost when an
 * award was missed once, and nothing is given twice.
 *
 * The lists are in the order of the Badge cases.
 */
final class BadgeEvaluatorTest extends TestCase
{
    /** The days of these tests have a limit of 20: not halved and not at the target, unless a test says so. */
    private const FIRST_LIMIT = 20;
    private const TARGET = 0;

    public function test_FR_GAME_08_the_badge_keys_are_stable(): void
    {
        // The keys are stored and sent to the clients, which have a picture and a text for each.
        self::assertSame(
            [
                'first_check_in',
                'first_smoke_free_day',
                'check_in_streak_7',
                'check_in_streak_30',
                'check_in_streak_100',
                'check_in_streak_365',
                'under_limit_streak_7',
                'under_limit_streak_30',
                'limit_halved',
                'target_reached',
                'first_duel_won',
                'five_duels_won',
                'league_champion_week',
                'league_champion_month',
                'league_champion_quarter',
                'league_champion_half_year',
                'cheerleader',
            ],
            array_map(static fn (Badge $badge): string => $badge->value, Badge::cases()),
        );
    }

    // ---- A day that became final -------------------------------------------------------------------

    public function test_FR_GAME_08_a_day_without_a_check_in_and_without_a_streak_earns_nothing(): void
    {
        self::assertSame([], $this->forDay(DayOutcome::missed(3, 20), StreakState::initial()));
    }

    public function test_FR_GAME_08_a_checked_in_day_earns_the_first_check_in(): void
    {
        // Over the limit: honest logging is what the badge is for.
        self::assertSame(
            [Badge::FirstCheckIn],
            $this->forDay(DayOutcome::checkedIn(25, 20), self::streaks(checkIn: 1, underLimit: 0)),
        );
    }

    public function test_FR_GAME_08_a_checked_in_day_without_a_session_earns_the_first_smoke_free_day(): void
    {
        self::assertSame(
            [Badge::FirstCheckIn, Badge::FirstSmokeFreeDay],
            $this->forDay(DayOutcome::checkedIn(0, 20), self::streaks(checkIn: 1, underLimit: 1)),
        );
    }

    public function test_FR_GAME_08_a_day_without_a_check_in_is_never_a_smoke_free_day(): void
    {
        // No log and no check-in: nobody said that the day was smoke-free (FR-LOG-03).
        self::assertSame([], $this->forDay(DayOutcome::missed(0, 20), StreakState::initial()));
    }

    /**
     * @param list<Badge> $expected
     */
    #[DataProvider('checkInStreaks')]
    public function test_FR_GAME_08_check_in_streak_badges_at_7_30_100_and_365_days(int $longest, array $expected): void
    {
        $streaks = new StreakState($longest, $longest, 0, 0, 0);

        self::assertSame(
            [Badge::FirstCheckIn, ...$expected],
            $this->forDay(DayOutcome::checkedIn(25, 20), $streaks),
        );
    }

    /**
     * @return iterable<string, array{int, list<Badge>}>
     */
    public static function checkInStreaks(): iterable
    {
        yield '6 days' => [6, []];
        yield '7 days' => [7, [Badge::CheckInStreak7]];
        yield '29 days' => [29, [Badge::CheckInStreak7]];
        yield '30 days' => [30, [Badge::CheckInStreak7, Badge::CheckInStreak30]];
        yield '99 days' => [99, [Badge::CheckInStreak7, Badge::CheckInStreak30]];
        yield '100 days' => [100, [Badge::CheckInStreak7, Badge::CheckInStreak30, Badge::CheckInStreak100]];
        yield '364 days' => [364, [Badge::CheckInStreak7, Badge::CheckInStreak30, Badge::CheckInStreak100]];
        yield '365 days' => [
            365,
            [Badge::CheckInStreak7, Badge::CheckInStreak30, Badge::CheckInStreak100, Badge::CheckInStreak365],
        ];
    }

    /**
     * @param list<Badge> $expected
     */
    #[DataProvider('underLimitStreaks')]
    public function test_FR_GAME_08_under_limit_streak_badges_at_7_and_30_days(int $longest, array $expected): void
    {
        // The check-in streak is 6 in every case with fewer than 7 under-limit days, and equal otherwise:
        // an under-limit streak is never longer than the check-in streak.
        $checkIn = max($longest, 6);
        $streaks = new StreakState($checkIn, $checkIn, $longest, $longest, 0);
        $checkInBadges = match (true) {
            $checkIn >= 30 => [Badge::CheckInStreak7, Badge::CheckInStreak30],
            $checkIn >= 7 => [Badge::CheckInStreak7],
            default => [],
        };

        self::assertSame(
            [Badge::FirstCheckIn, ...$checkInBadges, ...$expected],
            $this->forDay(DayOutcome::checkedIn(25, 20), $streaks),
        );
    }

    /**
     * @return iterable<string, array{int, list<Badge>}>
     */
    public static function underLimitStreaks(): iterable
    {
        yield '6 days' => [6, []];
        yield '7 days' => [7, [Badge::UnderLimitStreak7]];
        yield '29 days' => [29, [Badge::UnderLimitStreak7]];
        yield '30 days' => [30, [Badge::UnderLimitStreak7, Badge::UnderLimitStreak30]];
    }

    public function test_FR_GAME_08_streak_badges_follow_the_longest_streak_and_not_the_current_one(): void
    {
        // The streak broke today. The badge of the 7 days that were reached before is still justified:
        // an award that was missed once is made up for at the next day.
        $streaks = new StreakState(
            checkInCurrent: 0,
            checkInLongest: 7,
            underLimitCurrent: 0,
            underLimitLongest: 7,
            freezesHeld: 0,
        );

        self::assertSame(
            [Badge::CheckInStreak7, Badge::UnderLimitStreak7],
            $this->forDay(DayOutcome::missed(0, 20), $streaks),
        );
    }

    /**
     * @param list<Badge> $expected
     */
    #[DataProvider('halvedLimits')]
    public function test_FR_GAME_08_limit_halved_is_a_day_kept_at_half_the_first_limit_or_less(
        DayOutcome $day,
        int $firstLimit,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            array_values(array_filter(
                BadgeEvaluator::forDay($day, self::streaks(1, 0), $firstLimit, target: 0),
                static fn (Badge $badge): bool => $badge === Badge::LimitHalved,
            )),
        );
    }

    /**
     * @return iterable<string, array{DayOutcome, int, list<Badge>}>
     */
    public static function halvedLimits(): iterable
    {
        yield 'limit 10 of 20, kept' => [DayOutcome::checkedIn(10, 10), 20, [Badge::LimitHalved]];
        yield 'limit 9 of 20, kept' => [DayOutcome::checkedIn(4, 9), 20, [Badge::LimitHalved]];
        yield 'limit 11 of 20' => [DayOutcome::checkedIn(4, 11), 20, []];
        yield 'limit 10 of 21: exactly half is 10.5' => [DayOutcome::checkedIn(4, 10), 21, [Badge::LimitHalved]];
        yield 'limit 11 of 21' => [DayOutcome::checkedIn(4, 11), 21, []];
        yield 'limit 10 of 20, but over it' => [DayOutcome::checkedIn(11, 10), 20, []];
        yield 'limit 10 of 20, but no check-in' => [DayOutcome::missed(0, 10), 20, []];
        yield 'limit 0 of 1' => [DayOutcome::checkedIn(0, 0), 1, [Badge::LimitHalved]];
        // A plan that began with a limit of 0 has nothing to halve.
        yield 'limit 0 of 0' => [DayOutcome::checkedIn(0, 0), 0, []];
    }

    /**
     * @param list<Badge> $expected
     */
    #[DataProvider('targets')]
    public function test_FR_GAME_08_target_reached_is_a_day_kept_at_the_limit_that_has_arrived_at_the_target(
        DayOutcome $day,
        int $target,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            array_values(array_filter(
                BadgeEvaluator::forDay($day, self::streaks(1, 0), self::FIRST_LIMIT, $target),
                static fn (Badge $badge): bool => $badge === Badge::TargetReached,
            )),
        );
    }

    /**
     * @return iterable<string, array{DayOutcome, int, list<Badge>}>
     */
    public static function targets(): iterable
    {
        yield 'reduce to 5: limit 5, 5 sessions' => [DayOutcome::checkedIn(5, 5), 5, [Badge::TargetReached]];
        yield 'reduce to 5: limit 5, 2 sessions' => [DayOutcome::checkedIn(2, 5), 5, [Badge::TargetReached]];
        yield 'reduce to 5: limit 5, 6 sessions' => [DayOutcome::checkedIn(6, 5), 5, []];
        yield 'reduce to 5: limit 5, no check-in' => [DayOutcome::missed(0, 5), 5, []];
        // The limit is still on its way down: a single good day is not the target.
        yield 'reduce to 5: limit 6, 5 sessions' => [DayOutcome::checkedIn(5, 6), 5, []];
        yield 'quit: limit 0, no session' => [DayOutcome::checkedIn(0, 0), 0, [Badge::TargetReached]];
        yield 'quit: limit 0, one session' => [DayOutcome::checkedIn(1, 0), 0, []];
        yield 'quit: limit 1, no session' => [DayOutcome::checkedIn(0, 1), 0, []];
    }

    /**
     * @param list<Badge> $expected
     */
    #[DataProvider('targetsAgainstTheFirstLimit')]
    public function test_FR_GAME_08_a_target_is_reached_only_by_a_plan_that_began_above_it(
        int $firstLimit,
        int $target,
        array $expected,
    ): void {
        // A kept day whose limit is the target. What decides is where the plan began.
        $day = DayOutcome::checkedIn($target, $target);

        self::assertSame(
            $expected,
            array_values(array_filter(
                BadgeEvaluator::forDay($day, self::streaks(1, 0), $firstLimit, $target),
                static fn (Badge $badge): bool => $badge === Badge::TargetReached,
            )),
        );
    }

    /**
     * @return iterable<string, array{int, int, list<Badge>}>
     */
    public static function targetsAgainstTheFirstLimit(): iterable
    {
        yield 'began one above the target' => [6, 5, [Badge::TargetReached]];
        // The limit was the target from the first day on: nothing came down.
        yield 'began at the target' => [5, 5, []];
        // A target chosen later, above the limit of the first day: the limit went up to it.
        yield 'began below the target' => [4, 5, []];
        yield 'quit, began at 1' => [1, 0, [Badge::TargetReached]];
        yield 'quit, began at 0' => [0, 0, []];
    }

    public function test_FR_GAME_08_one_day_can_justify_many_badges_in_the_order_of_the_cases(): void
    {
        $streaks = new StreakState(30, 30, 30, 30, 2);

        self::assertSame(
            [
                Badge::FirstCheckIn,
                Badge::FirstSmokeFreeDay,
                Badge::CheckInStreak7,
                Badge::CheckInStreak30,
                Badge::UnderLimitStreak7,
                Badge::UnderLimitStreak30,
                Badge::LimitHalved,
                Badge::TargetReached,
            ],
            BadgeEvaluator::forDay(DayOutcome::checkedIn(0, 0), $streaks, firstLimit: 8, target: 0),
        );
    }

    public function test_FR_GAME_08_a_negative_first_limit_or_target_is_refused(): void
    {
        $day = DayOutcome::checkedIn(1, 5);

        foreach ([[-1, 0], [10, -1]] as [$firstLimit, $target]) {
            try {
                BadgeEvaluator::forDay($day, StreakState::initial(), $firstLimit, $target);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- Duels ------------------------------------------------------------------------------------

    /**
     * @param list<Badge> $expected
     */
    #[DataProvider('duelsWon')]
    public function test_FR_GAME_08_duel_badges_for_the_first_and_the_fifth_duel_won(int $won, array $expected): void
    {
        self::assertSame($expected, BadgeEvaluator::forDuelsWon($won));
    }

    /**
     * @return iterable<string, array{int, list<Badge>}>
     */
    public static function duelsWon(): iterable
    {
        yield 'none' => [0, []];
        yield 'one' => [1, [Badge::FirstDuelWon]];
        yield 'four' => [4, [Badge::FirstDuelWon]];
        yield 'five' => [5, [Badge::FirstDuelWon, Badge::FiveDuelsWon]];
        yield 'twelve' => [12, [Badge::FirstDuelWon, Badge::FiveDuelsWon]];
    }

    public function test_FR_GAME_08_a_negative_number_of_duels_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BadgeEvaluator::forDuelsWon(-1);
    }

    // ---- Leagues ----------------------------------------------------------------------------------

    #[DataProvider('leaguePeriods')]
    public function test_FR_COMP_08_rank_1_among_enough_participants_is_champion_of_that_period_type(
        LeaguePeriod $period,
        Badge $badge,
    ): void {
        self::assertSame([$badge], BadgeEvaluator::forLeague($period, rank: 1, participants: 3, minParticipants: 3));
        self::assertSame([$badge], BadgeEvaluator::forLeague($period, rank: 1, participants: 9, minParticipants: 3));
    }

    /**
     * @return iterable<string, array{LeaguePeriod, Badge}>
     */
    public static function leaguePeriods(): iterable
    {
        yield 'week' => [LeaguePeriod::Week, Badge::LeagueChampionWeek];
        yield 'month' => [LeaguePeriod::Month, Badge::LeagueChampionMonth];
        yield 'quarter' => [LeaguePeriod::Quarter, Badge::LeagueChampionQuarter];
        yield 'half-year' => [LeaguePeriod::HalfYear, Badge::LeagueChampionHalfYear];
    }

    public function test_FR_COMP_08_no_champion_below_the_minimum_number_of_participants(): void
    {
        // The same minimum as for the XP of a rank: being first of two, or alone, is no title.
        self::assertSame([], BadgeEvaluator::forLeague(LeaguePeriod::Week, rank: 1, participants: 2, minParticipants: 3));
        self::assertSame([], BadgeEvaluator::forLeague(LeaguePeriod::Week, rank: 1, participants: 1, minParticipants: 3));
        self::assertSame(
            [Badge::LeagueChampionWeek],
            BadgeEvaluator::forLeague(LeaguePeriod::Week, rank: 1, participants: 2, minParticipants: 2),
        );
    }

    public function test_FR_COMP_08_only_rank_1_is_champion(): void
    {
        self::assertSame([], BadgeEvaluator::forLeague(LeaguePeriod::Month, rank: 2, participants: 5, minParticipants: 3));
        self::assertSame([], BadgeEvaluator::forLeague(LeaguePeriod::Month, rank: 5, participants: 5, minParticipants: 3));
    }

    public function test_FR_COMP_08_a_rank_that_cannot_be_is_refused(): void
    {
        foreach ([[0, 3], [4, 3]] as [$rank, $participants]) {
            try {
                BadgeEvaluator::forLeague(LeaguePeriod::Week, $rank, $participants, minParticipants: 3);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- Reactions --------------------------------------------------------------------------------

    public function test_FR_GAME_08_cheerleader_after_50_reactions_sent(): void
    {
        self::assertSame([], BadgeEvaluator::forReactionsSent(0));
        self::assertSame([], BadgeEvaluator::forReactionsSent(49));
        self::assertSame([Badge::Cheerleader], BadgeEvaluator::forReactionsSent(50));
        self::assertSame([Badge::Cheerleader], BadgeEvaluator::forReactionsSent(51));
    }

    public function test_FR_GAME_08_a_negative_number_of_reactions_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BadgeEvaluator::forReactionsSent(-1);
    }

    // ---- Helpers ----------------------------------------------------------------------------------

    /**
     * @return list<Badge>
     */
    private function forDay(DayOutcome $day, StreakState $after): array
    {
        return BadgeEvaluator::forDay($day, $after, self::FIRST_LIMIT, self::TARGET);
    }

    private static function streaks(int $checkIn, int $underLimit): StreakState
    {
        return new StreakState($checkIn, $checkIn, $underLimit, $underLimit, 0);
    }
}
