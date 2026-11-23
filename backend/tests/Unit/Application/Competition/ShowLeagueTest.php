<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Competition;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\LeagueAward;
use SmokeDetector\Application\Competition\LeagueNotFound;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LeaguesUseCaseTestCase;

/**
 * What a user sees of a league (SRS FR-COMP-06, FR-COMP-08, FR-FRND-03; HLD §4.9): the user and their
 * friends in ranking order. While the result is not final, friends are positions without a
 * percentage. Once it is, the stored results are shown in whole percent.
 *
 * Today is Tuesday the 17th, the second day of the week 2026-W47. Yesterday counts for everybody,
 * today for those who have checked in.
 */
final class ShowLeagueTest extends LeaguesUseCaseTestCase
{
    private User $anna;
    private User $bob;
    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenPlayer('Anna');
        $this->bob = $this->givenPlayer('Bob');
        $this->carol = $this->givenPlayer('Carol');
        $this->givenFriends($this->anna, $this->bob);
        $this->givenFriends($this->carol, $this->anna);
    }

    public function test_FR_COMP_08_a_running_period_shows_positions_and_the_own_percentage_only(): void
    {
        // Yesterday: Anna 6, Bob nothing (smoke-free), Carol 12. Today Bob has 3 and has checked in.
        $this->givenDays($this->anna, '2026-11-16', [6]);
        $this->givenDays($this->bob, '2026-11-16', [0, 3]);
        $this->givenDays($this->carol, '2026-11-16', [12, [2]]);

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, 'current');

        self::assertSame([LeaguePeriod::Week, '2026-W47', '2026-11-16', '2026-11-22', false, null], [$view->period->type, $view->period->key, $view->period->first()->value, $view->period->last()->value, $view->final, $view->award]);
        // Bob: 3 of 24 over two days. Anna: 6 of 12. Carol: 12 of 12, her today does not count yet.
        self::assertSame(
            [[1, 'Bob', null, 1, 2, 2], [2, 'Anna (you)', 50, 0, 1, 1], [3, 'Carol', null, 0, 1, 1]],
            self::entries($view),
        );
        self::assertSame([$this->bob->id->value, 'Bob', 'avatar-07'], self::profile($view->entries[0]->user));
        // The same league seen by Bob: he is not Carol's friend, so she is not in his.
        self::assertSame(
            [[1, 'Bob (you)', 88, 1, 2, 2], [2, 'Anna', null, 0, 1, 1]],
            self::entries($this->showLeague()->show($this->bob->id, LeaguePeriod::Week, 'current')),
        );
    }

    public function test_FR_COMP_06_the_key_of_the_current_period_names_the_same_league(): void
    {
        $this->givenDays($this->anna, '2026-11-16', [6]);

        foreach ([[LeaguePeriod::Week, '2026-W47'], [LeaguePeriod::Month, '2026-11'], [LeaguePeriod::Quarter, '2026-Q4'], [LeaguePeriod::HalfYear, '2026-H2']] as [$type, $key]) {
            $current = $this->showLeague()->show($this->anna->id, $type, 'current');
            self::assertSame($key, $current->period->key);
            self::assertEquals($current, $this->showLeague()->show($this->anna->id, $type, $key));
        }
    }

    public function test_FR_COMP_06_a_longer_period_counts_from_its_first_day_or_from_the_first_day_of_the_plan(): void
    {
        // Anna is on board since the 1st of October, Dave since the 10th of November.
        $dave = $this->givenPlayer('Dave', '2026-11-10');
        $this->givenFriends($this->anna, $dave);
        $this->givenDays($this->anna, '2026-11-01', array_fill(0, 16, 6));
        $this->givenDays($dave, '2026-11-10', array_fill(0, 7, 3));

        $month = self::entries($this->showLeague()->show($this->anna->id, LeaguePeriod::Month, 'current'));
        $quarter = self::entries($this->showLeague()->show($dave->id, LeaguePeriod::Quarter, 'current'));

        // November so far: 16 days for Anna, 7 for Dave. Bob and Carol did nothing: every day at the baseline.
        self::assertSame([[1, 'Dave', null, 0, 7, 7], [2, 'Anna (you)', 50, 0, 16, 16], [3, 'Bob', null, 0, 0, 16], [3, 'Carol', null, 0, 0, 16]], $month);
        // The quarter since the 1st of October for Anna (47 days, 31 of them at the baseline), for Dave since the 10th of November.
        self::assertSame([[1, 'Dave (you)', 75, 0, 7, 7], [2, 'Anna', null, 0, 16, 47]], $quarter);
    }

    public function test_FR_COMP_06_somebody_without_a_day_that_counts_is_listed_last_without_a_rank(): void
    {
        // No plan. A plan from tomorrow on. And a plan since today, not checked in yet.
        $noPlan = $this->givenNamed('Zoe');
        $tomorrow = $this->givenPlayer('Yann', '2026-11-18');
        $today = $this->givenPlayer('Xaver', '2026-11-17');
        foreach ([$noPlan, $tomorrow, $today] as $friend) {
            $this->givenFriends($this->anna, $friend);
        }
        $this->givenDays($this->anna, '2026-11-16', [6]);

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, 'current');

        // Ranked first, by rank and then by name. Then the others by name.
        self::assertSame(
            [
                [1, 'Anna (you)', 50, 0, 1, 1], [2, 'Bob', null, 0, 0, 1], [2, 'Carol', null, 0, 0, 1],
                [null, 'Xaver', null, null, null, null], [null, 'Yann', null, null, null, null], [null, 'Zoe', null, null, null, null],
            ],
            self::entries($view),
        );
    }

    public function test_FR_COMP_06_the_own_entry_has_no_rank_either_before_the_first_day_counts(): void
    {
        // Monday the 16th of November at one o'clock at night in Vienna: nothing of the week counts yet.
        $this->clock->travelTo(TestData::time('2026-11-16 00:00:00'));

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, 'current');

        self::assertSame('2026-W47', $view->period->key);
        self::assertSame([[null, 'Anna (you)', null, null, null, null], [null, 'Bob', null, null, null, null], [null, 'Carol', null, null, null, null]], self::entries($view));
    }

    public function test_FR_COMP_08_positions_are_decided_exactly_and_equal_ones_share_a_rank(): void
    {
        $dave = $this->givenPlayer('Dave');
        $this->givenFriends($this->anna, $dave);
        // The quarter so far, 47 days. Anna one session less than Bob: the same whole percent, and ahead.
        $this->givenDays($this->anna, '2026-10-01', [...array_fill(0, 46, 6), 5]);
        $this->givenDays($this->bob, '2026-10-01', array_fill(0, 47, 6));
        // Carol and Dave are equal in everything.
        $this->givenDays($this->carol, '2026-10-01', array_fill(0, 47, 9));
        $this->givenDays($dave, '2026-10-01', array_fill(0, 47, 9));

        $view = $this->showLeague()->show($this->bob->id, LeaguePeriod::Quarter, 'current');
        $annas = $this->showLeague()->show($this->anna->id, LeaguePeriod::Quarter, 'current');

        self::assertSame([[1, 'Anna', null, 0, 47, 47], [2, 'Bob (you)', 50, 0, 47, 47]], self::entries($view));
        self::assertSame(
            [[1, 'Anna (you)', 50, 0, 47, 47], [2, 'Bob', null, 0, 47, 47], [3, 'Carol', null, 0, 47, 47], [3, 'Dave', null, 0, 47, 47]],
            self::entries($annas),
        );
    }

    public function test_FR_COMP_08_a_final_league_shows_the_stored_results_in_whole_percent_and_the_reward(): void
    {
        // Last week. Anna's rank was decided an hour ago, among three.
        $this->givenResult($this->anna, 42, 1, 7, award: new LeagueAward(1, 3, 150, TestData::time('2026-11-17 11:05:00')));
        $this->givenResult($this->bob, 47, 2, 6);
        $this->givenResult($this->carol, 84, 0, 5);
        // What they did since does not matter for last week.
        $this->givenDays($this->bob, '2026-11-09', [0, 0, 0, 0, 0, 0, 0, 0]);

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, '2026-W46');

        self::assertSame(['2026-W46', '2026-11-09', '2026-11-15', true], [$view->period->key, $view->period->first()->value, $view->period->last()->value, $view->final]);
        // 42, 47 and 84 of 84: 50 %, 44.05 % shown as 44, and 0 %.
        self::assertSame([[1, 'Anna (you)', 50, 1, 7, 7], [2, 'Bob', 44, 2, 6, 7], [3, 'Carol', 0, 0, 5, 7]], self::entries($view));
        self::assertEquals(new LeagueAward(1, 3, 150, TestData::time('2026-11-17 11:05:00')), $view->award);
    }

    public function test_FR_COMP_08_a_period_that_is_over_and_not_ranked_yet_still_shows_positions_only(): void
    {
        // Last week: Anna and Bob have their results, Carol's last day is not final yet. Nobody is ranked.
        $this->givenResult($this->anna, 42);
        $this->givenResult($this->bob, 60);
        $this->givenProgress($this->carol, '2026-11-15');
        $this->givenDays($this->carol, '2026-11-09', [0, 0, 0, 0, 0, 0, [3]]);

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, '2026-W46');

        self::assertSame([false, null], [$view->final, $view->award]);
        // Carol's week as it stands: six smoke-free days and a day without a check-in, 12 of 84.
        self::assertSame([[1, 'Carol', null, 6, 6, 7], [2, 'Anna (you)', 50, 0, 7, 7], [3, 'Bob', null, 0, 7, 7]], self::entries($view));
    }

    public function test_FR_COMP_08_in_a_final_league_a_friend_without_a_stored_result_has_a_position_and_no_percentage(): void
    {
        // Anna was ranked after the seven days of waiting, without Carol, whose days are still not final.
        $this->givenResult($this->anna, 42, award: new LeagueAward(1, 2, 0, TestData::time('2026-11-17 11:05:00')));
        $this->givenResult($this->bob, 60);
        $this->givenProgress($this->carol, '2026-11-12');
        $this->givenDays($this->carol, '2026-11-09', [0, 0, 0, 0, 0, 0, 0]);

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, '2026-W46');

        // The list is ranked as things stand today. The reward is the one that was decided.
        self::assertTrue($view->final);
        self::assertSame([[1, 'Carol', null, 7, 7, 7], [2, 'Anna (you)', 50, 0, 7, 7], [3, 'Bob', 29, 0, 7, 7]], self::entries($view));
        self::assertSame([1, 2, 0], [$view->award?->rank, $view->award?->participants, $view->award?->xp]);
    }

    public function test_FR_COMP_06_a_past_period_before_the_plan_of_a_friend_lists_the_friend_without_a_rank(): void
    {
        $dave = $this->givenPlayer('Dave', '2026-11-16');
        $this->givenFriends($this->anna, $dave);
        $this->givenResult($this->anna, 42, award: new LeagueAward(1, 1, 0, TestData::time('2026-11-17 11:05:00')));
        $this->givenResult($this->bob, 60);
        $this->givenResult($this->carol, 84);

        $entries = self::entries($this->showLeague()->show($this->anna->id, LeaguePeriod::Week, '2026-W46'));

        self::assertSame([null, 'Dave', null, null, null, null], $entries[3]);
        // A period before the user's own plan: nobody is rated who was not there.
        self::assertSame(
            [[null, 'Anna', null, null, null, null], [null, 'Dave (you)', null, null, null, null]],
            self::entries($this->showLeague()->show($dave->id, LeaguePeriod::Month, '2026-09')),
        );
    }

    public function test_FR_FRND_02_only_friends_are_in_the_league(): void
    {
        $asking = $this->givenPlayer('Dave');
        $this->givenRequest($asking, $this->anna);
        $this->givenPlayer('Erin');
        $this->givenDays($this->anna, '2026-11-16', [6]);

        $names = array_column(self::entries($this->showLeague()->show($this->anna->id, LeaguePeriod::Week, 'current')), 1);

        self::assertSame(['Anna (you)', 'Bob', 'Carol'], $names);
    }

    public function test_FR_COMP_06_a_period_that_has_not_begun_for_the_user_is_no_league(): void
    {
        foreach ([[LeaguePeriod::Week, '2026-W48'], [LeaguePeriod::Month, '2026-12'], [LeaguePeriod::Quarter, '2027-Q1'], [LeaguePeriod::HalfYear, '2027-H1']] as [$type, $key]) {
            try {
                $this->showLeague()->show($this->anna->id, $type, $key);
                self::fail("{$key} should have been refused.");
            } catch (LeagueNotFound) {
                $this->addToAssertionCount(1);
            }
        }
        // It begins with the user's own Monday: in Auckland it is the 18th already, in Vienna not.
        $bob = $this->givenZoneChange($this->bob, '2026-10-02', 'Pacific/Auckland');
        $this->clock->advanceSeconds(5 * 86_400 + 3600);
        // Sunday the 22nd, 13:00 UTC: Monday the 23rd, 02:00 in Auckland.
        self::assertSame('2026-W48', $this->showLeague()->show($bob->id, LeaguePeriod::Week, '2026-W48')->period->key);
        $this->expectException(LeagueNotFound::class);
        $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, '2026-W48');
    }

    public function test_FR_COMP_06_a_key_that_names_no_period_is_refused_as_input(): void
    {
        foreach ([[LeaguePeriod::Week, '2026-11'], [LeaguePeriod::Week, '2025-W53'], [LeaguePeriod::Month, 'now']] as [$type, $key]) {
            try {
                $this->showLeague()->show($this->anna->id, $type, $key);
                self::fail("{$key} should have been refused.");
            } catch (InvalidValue) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_FR_ACC_06_without_a_plan_there_is_no_league_and_without_an_account_neither(): void
    {
        $noPlan = $this->givenNamed('Zoe');

        try {
            $this->showLeague()->show($noPlan->id, LeaguePeriod::Week, 'current');
            self::fail('Should have been refused.');
        } catch (PlanRequired) {
            $this->addToAssertionCount(1);
        }

        $this->users->delete($this->anna->id);
        $this->expectException(AccountNotFound::class);
        $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, 'current');
    }

    public function test_NFR_01_looking_at_a_league_locks_nothing_and_writes_nothing(): void
    {
        $this->givenResult($this->anna, 42);
        $before = $this->leagueResults->all();

        $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, '2026-W46');
        $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, 'current');

        self::assertSame([], $this->users->lockLog);
        self::assertEquals($before, $this->leagueResults->all());
    }
}
