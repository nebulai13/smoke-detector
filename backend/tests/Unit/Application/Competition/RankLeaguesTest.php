<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Competition;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\LeagueAward;
use SmokeDetector\Application\Competition\LeagueReport;
use SmokeDetector\Application\Competition\LeagueResult;
use SmokeDetector\Application\Competition\RankLeagues;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LeaguesUseCaseTestCase;

/**
 * The cron step that ranks league results (SRS FR-COMP-06, FR-COMP-08, OQ-8; HLD §4.9): a user's
 * stored result of a period gets its rank among the user and the user's friends, once all of them
 * are settled, and rank 1 to 3 among at least three earns XP.
 */
final class RankLeaguesTest extends LeaguesUseCaseTestCase
{
    public function test_FR_COMP_08_three_friends_are_ranked_and_rewarded_by_their_reduction(): void
    {
        [$anna, $bob, $carol] = $this->givenFriendsAll('Anna', 'Bob', 'Carol');
        $this->givenResult($anna, 42);
        $this->givenResult($bob, 60, 2);
        $this->givenResult($carol, 84);
        $before = $this->progressRecords->find($anna->id) ?? self::fail('No progress.');

        $report = $this->rankLeagues()->execute();

        self::assertSame([3, 0, 0], self::report($report));
        self::assertSame([[1, 3, 150], [2, 3, 100], [3, 3, 50]], [$this->awardOf($anna), $this->awardOf($bob), $this->awardOf($carol)]);
        self::assertEquals(new LeagueAward(1, 3, 150, $this->now()), $this->resultOf($anna)->award);
        // Nothing else of the result changes.
        self::assertSame([420, 840, 0, 7], [$this->resultOf($anna)->actualTenths, $this->resultOf($anna)->expectedTenths, $this->resultOf($anna)->smokeFreeDays, $this->resultOf($anna)->checkIns]);
        // The XP is in the ledger, earned today, with the rank as its detail. 150 XP are level 1.
        self::assertSame([['league', '2026-11-17', 150, '2026-11-17 12:00:00']], $this->awards($anna));
        self::assertSame(1, $this->xpLedger->ofUser($anna->id)[0]->detail);
        self::assertSame([['league', '2026-11-17', 50, '2026-11-17 12:00:00']], $this->awards($carol));
        self::assertEquals(
            new ProgressRecord($anna->id, new UserProgress($before->progress->streaks, 150), 1, $before->nextDay, $before->finalizeDueAt, $before->baselineDueAt),
            $this->progressRecords->find($anna->id),
        );
        // A second run finds nothing to do.
        self::assertSame([0, 0, 0], self::report($this->rankLeagues()->execute()));
        self::assertCount(1, $this->awards($anna));
    }

    public function test_FR_COMP_08_with_fewer_than_three_participants_there_is_a_rank_and_no_xp(): void
    {
        [$anna, $bob] = $this->givenFriendsAll('Anna', 'Bob');
        $this->givenResult($anna, 42);
        $this->givenResult($bob, 60);
        $loner = $this->givenPlayer('Loner');
        $this->givenResult($loner, 84);
        $before = $this->progressRecords->find($anna->id);

        $this->rankLeagues()->execute();

        self::assertSame([[1, 2, 0], [2, 2, 0], [1, 1, 0]], [$this->awardOf($anna), $this->awardOf($bob), $this->awardOf($loner)]);
        self::assertSame([[], [], []], [$this->awards($anna), $this->awards($bob), $this->awards($loner)]);
        self::assertEquals($before, $this->progressRecords->find($anna->id));
    }

    public function test_OQ_8_the_same_reduction_is_decided_by_smoke_free_days_then_by_check_ins(): void
    {
        [$anna, $bob, $carol, $dave] = $this->givenFriendsAll('Anna', 'Bob', 'Carol', 'Dave');
        // All four 60 of 84.
        $this->givenResult($anna, 60, 1, 7);
        $this->givenResult($bob, 60, 2, 6);
        $this->givenResult($carol, 60, 1, 6);
        $this->givenResult($dave, 60, 2, 7);

        $this->rankLeagues()->execute();

        // Dave and Bob have two smoke-free days, Dave more check-ins. Then Anna before Carol.
        self::assertSame(
            [[3, 4, 50], [2, 4, 100], [4, 4, 0], [1, 4, 150]],
            [$this->awardOf($anna), $this->awardOf($bob), $this->awardOf($carol), $this->awardOf($dave)],
        );
        self::assertSame([], $this->awards($carol));
    }

    public function test_OQ_8_equal_on_all_three_share_the_rank_and_the_next_rank_is_left_out(): void
    {
        [$anna, $bob, $carol, $dave] = $this->givenFriendsAll('Anna', 'Bob', 'Carol', 'Dave');
        $this->givenResult($anna, 42, 1);
        $this->givenResult($bob, 42, 1);
        $this->givenResult($carol, 60);
        $this->givenResult($dave, 60);

        $this->rankLeagues()->execute();

        // 1, 1, 3, 3: both first get the XP of rank 1, both third the XP of rank 3.
        self::assertSame(
            [[1, 4, 150], [1, 4, 150], [3, 4, 50], [3, 4, 50]],
            [$this->awardOf($anna), $this->awardOf($bob), $this->awardOf($carol), $this->awardOf($dave)],
        );
    }

    public function test_FR_COMP_06_reductions_are_compared_exactly(): void
    {
        [$anna, $bob, $carol] = $this->givenFriendsAll('Anna', 'Bob', 'Carol');
        // A half-year of 181 days: 1086 against 1087 of 2172 sessions. Both are "50 %".
        $this->givenResult($anna, 1087, 0, null, '2026-H1', LeaguePeriod::HalfYear);
        $this->givenResult($bob, 1086, 0, null, '2026-H1', LeaguePeriod::HalfYear);
        $this->givenResult($carol, 2172, 0, null, '2026-H1', LeaguePeriod::HalfYear);

        $this->rankLeagues()->execute();

        self::assertSame([2, 1, 3], [$this->awardOf($anna, '2026-H1', LeaguePeriod::HalfYear)[0] ?? null, $this->awardOf($bob, '2026-H1', LeaguePeriod::HalfYear)[0] ?? null, $this->awardOf($carol, '2026-H1', LeaguePeriod::HalfYear)[0] ?? null]);
    }

    public function test_FR_GAME_01_a_longer_period_multiplies_the_xp(): void
    {
        [$anna, $bob, $carol] = $this->givenFriendsAll('Anna', 'Bob', 'Carol');
        foreach ([[LeaguePeriod::Week, '2026-W46'], [LeaguePeriod::Month, '2026-10'], [LeaguePeriod::Quarter, '2026-Q3'], [LeaguePeriod::HalfYear, '2026-H1']] as [$type, $key]) {
            $this->givenResult($anna, 10, 0, null, $key, $type);
            $this->givenResult($bob, 20, 0, null, $key, $type);
            $this->givenResult($carol, 30, 0, null, $key, $type);
        }

        $this->rankLeagues()->execute();

        // Times 1, 2, 3 and 4.
        self::assertSame(
            [[1, 3, 150], [1, 3, 300], [1, 3, 450], [1, 3, 600]],
            [$this->awardOf($anna), $this->awardOf($anna, '2026-10', LeaguePeriod::Month), $this->awardOf($anna, '2026-Q3', LeaguePeriod::Quarter), $this->awardOf($anna, '2026-H1', LeaguePeriod::HalfYear)],
        );
        self::assertSame([2, 3, 200], $this->awardOf($bob, '2026-10', LeaguePeriod::Month));
        self::assertSame([3, 3, 200], $this->awardOf($carol, '2026-H1', LeaguePeriod::HalfYear));
        self::assertSame(1500, $this->progressRecords->find($anna->id)?->progress->totalXp);
    }

    public function test_FR_COMP_06_a_league_is_the_user_and_the_users_own_friends(): void
    {
        // Anna is friends with Bob and Carol. Bob and Carol are not friends of each other.
        $anna = $this->givenPlayer('Anna');
        $bob = $this->givenPlayer('Bob');
        $carol = $this->givenPlayer('Carol');
        $this->givenFriends($anna, $bob);
        $this->givenFriends($carol, $anna);
        // Somebody who has asked Anna and was not accepted is no friend, and neither is a stranger.
        $dave = $this->givenPlayer('Dave');
        $this->givenRequest($dave, $anna);
        $erin = $this->givenPlayer('Erin');
        $this->givenResult($anna, 60);
        $this->givenResult($bob, 42);
        $this->givenResult($carol, 84);
        $this->givenResult($dave, 0);
        $this->givenResult($erin, 0);

        $this->rankLeagues()->execute();

        // Anna among three: second. Bob and Carol each among two, with Anna.
        self::assertSame([[2, 3, 100], [1, 2, 0], [2, 2, 0], [1, 1, 0]], [$this->awardOf($anna), $this->awardOf($bob), $this->awardOf($carol), $this->awardOf($dave)]);
    }

    public function test_FR_COMP_08_a_result_waits_while_a_friend_who_will_have_one_is_not_through(): void
    {
        self::assertSame([15, 7], [RankLeagues::RECHECK_MINUTES, RankLeagues::MAX_WAIT_DAYS]);
        [$anna, $bob, $carol] = $this->givenFriendsAll('Anna', 'Bob', 'Carol');
        $this->givenResult($anna, 42);
        $this->givenResult($carol, 84);
        // Bob's last day of the week is not final yet: he has a plan since October and no result.

        $report = $this->rankLeagues()->execute();

        self::assertSame([0, 2, 0], self::report($report));
        self::assertSame([null, null], [$this->awardOf($anna), $this->awardOf($carol)]);
        // Looked at again in 15 minutes, and not before.
        self::assertEquals($this->now()->modify('+15 minutes'), $this->resultOf($anna)->checkAt);
        $this->clock->advanceSeconds(899);
        self::assertSame([0, 0, 0], self::report($this->rankLeagues()->execute()));
        $this->clock->advanceSeconds(1);
        self::assertSame([0, 2, 0], self::report($this->rankLeagues()->execute()));

        // Bob's result arrives.
        $this->givenResult($bob, 60, 0, null, '2026-W46', LeaguePeriod::Week, '2026-11-17 12:20:00');
        $this->clock->advanceSeconds(900);
        self::assertSame([3, 0, 0], self::report($this->rankLeagues()->execute()));
        self::assertSame([[1, 3, 150], [2, 3, 100], [3, 3, 50]], [$this->awardOf($anna), $this->awardOf($bob), $this->awardOf($carol)]);
    }

    public function test_FR_COMP_06_a_friend_who_cannot_have_a_result_holds_nobody_up_and_is_no_participant(): void
    {
        $anna = $this->givenPlayer('Anna');
        $bob = $this->givenPlayer('Bob');
        // No plan at all. And a plan that started on the Monday after the week.
        $noPlan = $this->givenNamed('Carol');
        $late = $this->givenPlayer('Dave', '2026-11-16');
        foreach ([$bob, $noPlan, $late] as $friend) {
            $this->givenFriends($anna, $friend);
        }
        $this->givenResult($anna, 42);
        $this->givenResult($bob, 60);

        $this->rankLeagues()->execute();

        self::assertSame([1, 2, 0], $this->awardOf($anna));
    }

    public function test_FR_COMP_06_a_friend_who_joined_on_the_last_day_of_the_period_is_waited_for(): void
    {
        $anna = $this->givenPlayer('Anna');
        // On board since Sunday the 15th, the last day of the week: there will be a result.
        $bob = $this->givenPlayer('Bob', '2026-11-15');
        $this->givenFriends($anna, $bob);
        $this->givenResult($anna, 42);

        self::assertSame([0, 1, 0], self::report($this->rankLeagues()->execute()));
        self::assertNull($this->awardOf($anna));
    }

    public function test_FR_COMP_08_after_seven_days_a_result_is_ranked_with_what_is_there(): void
    {
        [$anna, $bob, $carol] = $this->givenFriendsAll('Anna', 'Bob', 'Carol');
        // Scored seven days ago to the second. Bob still has no result.
        $this->givenResult($anna, 42, 0, null, '2026-W45', LeaguePeriod::Week, '2026-11-10 12:00:00');
        $this->givenResult($carol, 84, 0, null, '2026-W45', LeaguePeriod::Week, '2026-11-10 12:00:01');

        $report = $this->rankLeagues()->execute();

        // Anna is ranked without Bob. Carol waits a second longer.
        self::assertSame([1, 1, 0], self::report($report));
        self::assertSame([1, 2, 0], $this->awardOf($anna, '2026-W45'));
        self::assertNull($this->awardOf($carol, '2026-W45'));
    }

    public function test_NFR_01_a_result_is_ranked_with_the_account_of_its_user_locked_and_looked_at_again_after_the_lock(): void
    {
        [$anna, $bob] = $this->givenFriendsAll('Anna', 'Bob');
        $result = $this->givenResult($anna, 42);
        $this->givenResult($bob, 60, 0, null, '2026-W46', LeaguePeriod::Week, '2026-11-17 11:30:00');
        // While the step waits for Anna's account, another run ranks her result.
        $ranked = new LeagueResult(
            $result->userId, $result->period, 120, 420, 840, 0, 7, 7, 'v1', $result->scoredAt, $result->checkAt, new LeagueAward(1, 2, 0, $this->now()),
        );
        $this->users->beforeLock = fn () => $this->leagueResults->save($ranked);

        $report = $this->rankLeagues()->execute();

        // Hers is left as the other run decided it. Bob's is ranked. One lock each, in the order they were taken.
        self::assertSame([1, 0, 0], self::report($report));
        self::assertEquals($ranked, $this->resultOf($anna));
        self::assertSame([$anna->id->value, $bob->id->value], $this->users->lockLog);
    }

    public function test_NFR_01_a_result_that_cannot_be_ranked_does_not_stop_the_others_and_is_reported(): void
    {
        [$anna, $bob] = $this->givenFriendsAll('Anna', 'Bob');
        // Damaged data: a result of somebody without a plan and without progress.
        $ghost = $this->givenNamed('Ghost');
        $damaged = $this->givenResult($ghost, 10, 0, null, '2026-W46', LeaguePeriod::Week, '2026-11-17 10:00:00');
        $this->givenFriends($ghost, $anna);
        $this->givenFriends($ghost, $bob);
        $this->givenResult($anna, 42);
        $this->givenResult($bob, 60);

        $report = $this->rankLeagues()->execute();

        // The ghost would be first of three and earn XP, which cannot be booked without progress.
        self::assertSame([2, 0, 1], self::report($report));
        // Not ranked, and looked at again in 15 minutes: a result that fails every time must not be the
        // first of every run, in front of the ones that can be ranked.
        self::assertNull($this->resultOf($ghost)->award);
        self::assertEquals($this->now()->modify('+15 minutes'), $this->resultOf($ghost)->checkAt);
        self::assertSame([$damaged->actualTenths, $damaged->scoredAt], [$this->resultOf($ghost)->actualTenths, $this->resultOf($ghost)->scoredAt]);
        self::assertSame([0, 0, 0], self::report($this->rankLeagues()->execute()));
        self::assertSame([], $this->xpLedger->ofUser($ghost->id));
        self::assertSame([[2, 3, 100], [3, 3, 50]], [$this->awardOf($anna), $this->awardOf($bob)]);
        self::assertCount(1, $this->logger->records);
        self::assertSame(['level' => 'error', 'message' => 'A league result could not be ranked.'], array_intersect_key($this->logger->records[0], ['level' => 1, 'message' => 1]));
        self::assertSame(['exception'], array_keys($this->logger->records[0]['context']));
        foreach ([$ghost->id->value, $anna->id->value, '2026-W46'] as $trace) {
            self::assertStringNotContainsString($trace, $this->logger->text());
        }
    }

    public function test_FR_ACC_08_a_result_whose_account_is_gone_is_left_alone(): void
    {
        [$anna, $bob] = $this->givenFriendsAll('Anna', 'Bob');
        $this->givenResult($anna, 42);
        $this->givenResult($bob, 60);
        // Bob's account is deleted while the step waits. (The eraser takes his rows with it.)
        $this->users->beforeLock = function () use ($anna, $bob): void {
            $this->users->delete($bob->id);
            $this->friendLinks->delete($bob->id, $anna->id);
        };

        $report = $this->rankLeagues()->execute();

        self::assertSame([1, 0, 0], self::report($report));
        self::assertSame([1, 1, 0], $this->awardOf($anna));
        self::assertNull($this->awardOf($bob));
        self::assertSame([], $this->logger->records);
    }

    public function test_NFR_01_one_run_ranks_fifty_results_and_the_next_run_the_rest(): void
    {
        self::assertSame(50, RankLeagues::BATCH_SIZE);
        for ($i = 1; $i <= 51; $i++) {
            $this->givenResult($this->givenPlayer("Player {$i}"), 42);
        }

        self::assertSame([50, 0, 0], self::report($this->rankLeagues()->execute()));
        self::assertSame([1, 0, 0], self::report($this->rankLeagues()->execute()));
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

    /**
     * @return array{int, int, int} Ranked, waiting, failed.
     */
    private static function report(LeagueReport $report): array
    {
        return [$report->ranked, $report->waiting, $report->failed];
    }
}
