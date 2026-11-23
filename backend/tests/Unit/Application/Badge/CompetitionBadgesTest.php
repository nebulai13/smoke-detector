<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Badge;

use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Badge\AwardedBadge;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Competition\DuelWinner;
use SmokeDetector\Application\Competition\SideResult;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LeaguesUseCaseTestCase;

/**
 * The badges of the duels and the leagues (HLD §4.10 "When a badge is awarded"; FR-GAME-08,
 * FR-COMP-08, Appendix B): awarded in the transaction that decides a duel or ranks a league result,
 * from what is stored.
 *
 * It is noon UTC on Tuesday, 17 November 2026.
 */
final class CompetitionBadgesTest extends LeaguesUseCaseTestCase
{
    private const string NOW = '2026-11-17 12:00:00';

    // ---- Duels ------------------------------------------------------------------------------------

    public function test_FR_GAME_08_the_winner_of_a_duel_earns_first_duel_won_and_the_loser_nothing(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        $this->givenDuelThatAnnaWins(1, $anna, $bob);

        $this->decideDuels()->execute();

        self::assertSame(DuelWinner::Challenger, $this->duels->find(TestData::uuid(1))?->decision?->winner);
        self::assertSame([['first_duel_won', self::NOW]], $this->badgesOf($anna));
        self::assertSame([], $this->badgesOf($bob));
    }

    public function test_FR_GAME_08_the_opponent_who_wins_earns_it_too(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        $this->givenRunning(1, $anna, $bob, '2026-11-03');
        $this->givenDays($anna, '2026-11-03', [12, 12, 12, 12, 12, 12, 12]);
        $this->givenDays($bob, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);

        $this->decideDuels()->execute();

        self::assertSame(DuelWinner::Opponent, $this->duels->find(TestData::uuid(1))?->decision?->winner);
        self::assertSame([[], ['first_duel_won']], [$this->badgeKeysOf($anna), $this->badgeKeysOf($bob)]);
    }

    public function test_FR_GAME_08_a_draw_is_no_duel_won(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        $this->givenRunning(1, $anna, $bob, '2026-11-03');
        $this->givenDays($anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        $this->givenDays($bob, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);

        $this->decideDuels()->execute();

        self::assertSame(DuelWinner::Draw, $this->duels->find(TestData::uuid(1))?->decision?->winner);
        self::assertSame([[], []], [$this->badgesOf($anna), $this->badgesOf($bob)]);
    }

    public function test_FR_GAME_08_the_fifth_duel_won_earns_five_duels_won(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        // Four duels Anna won before, two from each side, and the first of them earned its badge then.
        $this->givenDecided(11, $anna, $bob, DuelWinner::Challenger);
        $this->givenDecided(12, $bob, $anna, DuelWinner::Opponent);
        $this->givenDecided(13, $anna, $bob, DuelWinner::Challenger);
        $this->givenDecided(14, $bob, $anna, DuelWinner::Opponent);
        $this->badgeRecords->add(new AwardedBadge($anna->id, Badge::FirstDuelWon, TestData::time('2026-10-20 23:00:00')));
        $this->givenDuelThatAnnaWins(1, $anna, $bob);

        $this->decideDuels()->execute();

        self::assertSame(
            [['first_duel_won', '2026-10-20 23:00:00'], ['five_duels_won', self::NOW]],
            $this->badgesOf($anna),
        );
        self::assertSame([], $this->badgesOf($bob));
    }

    public function test_FR_GAME_08_only_duels_that_were_won_count_towards_the_five(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        // Three won. Then: lost, a draw, cancelled, and a forfeit because the other account was deleted.
        $this->givenDecided(11, $anna, $bob, DuelWinner::Challenger);
        $this->givenDecided(12, $bob, $anna, DuelWinner::Opponent);
        $this->givenDecided(13, $anna, $bob, DuelWinner::Challenger);
        $this->givenDecided(14, $anna, $bob, DuelWinner::Opponent);
        $this->givenDecided(15, $anna, $bob, DuelWinner::Draw);
        $this->givenEnded(16, $anna, $bob, DuelStatus::Cancelled);
        $this->givenEnded(17, $anna, $bob, DuelStatus::Forfeited);
        $this->givenDuelThatAnnaWins(1, $anna, $bob);

        $this->decideDuels()->execute();

        // The fourth duel won. The badge of the first is made up for.
        self::assertSame([['first_duel_won', self::NOW]], $this->badgesOf($anna));
        // Bob won duel 14, before there were badges, and lost today's: nothing is made up for a loser.
        self::assertSame([], $this->badgesOf($bob));
    }

    // ---- Leagues ----------------------------------------------------------------------------------

    public function test_FR_COMP_08_rank_1_among_three_earns_the_champion_badge_of_the_week(): void
    {
        [$anna, $bob, $carol] = $this->givenFriendsAll('Anna', 'Bob', 'Carol');
        $this->givenResult($anna, 42);
        $this->givenResult($bob, 60);
        $this->givenResult($carol, 84);

        $this->rankLeagues()->execute();

        self::assertSame([[1, 3, 150], [2, 3, 100], [3, 3, 50]], [$this->awardOf($anna), $this->awardOf($bob), $this->awardOf($carol)]);
        self::assertSame([['league_champion_week', self::NOW]], $this->badgesOf($anna));
        self::assertSame([[], []], [$this->badgesOf($bob), $this->badgesOf($carol)]);
    }

    public function test_FR_COMP_08_two_who_share_rank_1_are_both_champions(): void
    {
        [$anna, $bob, $carol] = $this->givenFriendsAll('Anna', 'Bob', 'Carol');
        $this->givenResult($anna, 42, 1);
        $this->givenResult($bob, 42, 1);
        $this->givenResult($carol, 60);

        $this->rankLeagues()->execute();

        self::assertSame(
            [['league_champion_week'], ['league_champion_week'], []],
            [$this->badgeKeysOf($anna), $this->badgeKeysOf($bob), $this->badgeKeysOf($carol)],
        );
    }

    public function test_FR_COMP_08_rank_1_of_two_or_alone_is_no_champion(): void
    {
        [$anna, $bob] = $this->givenFriendsAll('Anna', 'Bob');
        $this->givenResult($anna, 42);
        $this->givenResult($bob, 60);
        $loner = $this->givenPlayer('Loner');
        $this->givenResult($loner, 84);

        $this->rankLeagues()->execute();

        self::assertSame([[1, 2, 0], [1, 1, 0]], [$this->awardOf($anna), $this->awardOf($loner)]);
        self::assertSame([], $this->badgeRecords->all());
    }

    #[DataProvider('periods')]
    public function test_FR_COMP_08_every_period_type_has_a_champion_badge_of_its_own(LeaguePeriod $type, string $key, string $badge): void
    {
        [$anna, $bob, $carol] = $this->givenFriendsAll('Anna', 'Bob', 'Carol');
        $this->givenResult($anna, 10, key: $key, type: $type);
        $this->givenResult($bob, 20, key: $key, type: $type);
        $this->givenResult($carol, 30, key: $key, type: $type);

        $this->rankLeagues()->execute();

        self::assertSame([[$badge], [], []], [$this->badgeKeysOf($anna), $this->badgeKeysOf($bob), $this->badgeKeysOf($carol)]);
    }

    /**
     * @return iterable<string, array{LeaguePeriod, string, string}>
     */
    public static function periods(): iterable
    {
        yield 'week' => [LeaguePeriod::Week, '2026-W45', 'league_champion_week'];
        yield 'month' => [LeaguePeriod::Month, '2026-10', 'league_champion_month'];
        yield 'quarter' => [LeaguePeriod::Quarter, '2026-Q3', 'league_champion_quarter'];
        yield 'half-year' => [LeaguePeriod::HalfYear, '2026-H1', 'league_champion_half_year'];
    }

    public function test_FR_COMP_08_a_second_title_of_the_same_period_type_keeps_the_time_of_the_first(): void
    {
        [$anna, $bob, $carol] = $this->givenFriendsAll('Anna', 'Bob', 'Carol');
        foreach (['2026-W45', '2026-W46'] as $key) {
            $this->givenResult($anna, 42, key: $key);
            $this->givenResult($bob, 60, key: $key);
            $this->givenResult($carol, 84, key: $key);
        }
        $this->badgeRecords->add(new AwardedBadge($anna->id, Badge::LeagueChampionWeek, TestData::time('2026-11-10 23:00:00')));

        $this->rankLeagues()->execute();

        self::assertSame([['league_champion_week', '2026-11-10 23:00:00']], $this->badgesOf($anna));
    }

    public function test_a_result_that_waits_for_a_friend_earns_nothing_yet(): void
    {
        [$anna, $bob, $carol] = $this->givenFriendsAll('Anna', 'Bob', 'Carol');
        $this->givenResult($anna, 42);
        $this->givenResult($bob, 60);
        // Carol has a plan since October and no result for the week yet: her last day is not final.

        $this->rankLeagues()->execute();

        self::assertNull($this->awardOf($anna));
        self::assertSame([], $this->badgeRecords->all());
    }

    // ---- Helpers ----------------------------------------------------------------------------------

    /**
     * A running duel from the 3rd to the 9th in which Anna, the challenger, has half of Bob's sessions.
     */
    private function givenDuelThatAnnaWins(int $number, User $anna, User $bob): void
    {
        $this->givenRunning($number, $anna, $bob, '2026-11-03');
        $this->givenDays($anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        $this->givenDays($bob, '2026-11-03', [12, 12, 12, 12, 12, 12, 12]);
    }

    /**
     * A duel that was decided in October.
     */
    private function givenDecided(int $number, User $challenger, User $opponent, DuelWinner $winner): void
    {
        $won = new SideResult(120, 630, 840, 1, 7, 100);
        $lost = new SideResult(120, 840, 840, 0, 7, 20);
        $decision = match ($winner) {
            DuelWinner::Challenger => TestData::decision($winner, $won, $lost, '2026-10-20 23:00:00'),
            DuelWinner::Opponent => TestData::decision($winner, $lost, $won, '2026-10-20 23:00:00'),
            DuelWinner::Draw => TestData::decision($winner, $lost, $lost, '2026-10-20 23:00:00'),
        };
        $this->givenEnded($number, $challenger, $opponent, DuelStatus::Completed, $decision, '2026-10-10');
    }

    /**
     * Players with these names who are all friends of each other.
     *
     * @return list<User>
     */
    private function givenFriendsAll(string ...$names): array
    {
        $players = array_map(fn (string $name): User => $this->givenPlayer($name), array_values($names));
        foreach ($players as $index => $player) {
            foreach (array_slice($players, $index + 1) as $other) {
                $this->givenFriends($player, $other);
            }
        }

        return $players;
    }
}
