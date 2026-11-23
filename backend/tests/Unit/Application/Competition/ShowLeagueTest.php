<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Competition;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\CalendarPeriod;
use SmokeDetector\Application\Competition\LeagueAward;
use SmokeDetector\Application\Competition\LeagueResult;
use SmokeDetector\Application\Competition\ShowLeague;
use SmokeDetector\Application\Competition\LeagueNotFound;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LeaguesUseCaseTestCase;

/**
 * What a user sees of a league (SRS FR-COMP-06, FR-COMP-08, FR-FRND-03; HLD §4.9): the user and their
 * friends. While the result is not final, each friend is ahead of the user, level or behind, and
 * nothing else: no rank among the friends and no percentage, because both could be turned back into
 * what a friend smoked. Once it is final, everybody has a rank, and the stored results are shown in
 * whole percent.
 *
 * Today is Tuesday the 17th, the second day of the week 2026-W47. For the user's own entry yesterday
 * counts, and today once it is checked in. Of a friend only final days count, here the days before
 * today, and who is ahead is decided over the final days of both.
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

    public function test_FR_COMP_08_a_running_period_shows_who_is_ahead_and_the_own_rank_and_percentage_only(): void
    {
        // Yesterday: Anna 6, Bob nothing (smoke-free), Carol 12. Today Bob has 3 and has checked in.
        $this->givenDays($this->anna, '2026-11-16', [6]);
        $this->givenDays($this->bob, '2026-11-16', [0, 3]);
        $this->givenDays($this->carol, '2026-11-16', [12, [2]]);

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, 'current');

        self::assertSame([LeaguePeriod::Week, '2026-W47', '2026-11-16', '2026-11-22', false, null], [$view->period->type, $view->period->key, $view->period->first()->value, $view->period->last()->value, $view->final, $view->award]);
        // Anna: 6 of 12. Of Bob and Carol she sees yesterday, which is final: nothing and 12. Not that
        // Bob has checked in today.
        self::assertSame(
            [['ahead', 'Bob', null, 1, 1, 1], [2, 'Anna (you)', 50, 0, 1, 1], ['behind', 'Carol', null, 0, 1, 1]],
            self::entries($view),
        );
        self::assertSame([$this->bob->id->value, 'Bob', 'avatar-07'], self::profile($view->entries[0]->user));
        // The same league seen by Bob, with his own today: he is not Carol's friend, so she is not in his.
        self::assertSame(
            [[1, 'Bob (you)', 88, 1, 2, 2], ['behind', 'Anna', null, 0, 1, 1]],
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
        self::assertSame([['ahead', 'Dave', null, 0, 7, 7], [2, 'Anna (you)', 50, 0, 16, 16], ['behind', 'Bob', null, 0, 0, 16], ['behind', 'Carol', null, 0, 0, 16]], $month);
        // The quarter since the 1st of October for Anna (47 days, 31 of them at the baseline), for Dave since the 10th of November.
        self::assertSame([[1, 'Dave (you)', 75, 0, 7, 7], ['behind', 'Anna', null, 0, 16, 47]], $quarter);
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

        // Those who are ahead, the user, those who are level, those who are behind: each group by name.
        // Then the ones without a day that counts, by name.
        self::assertSame(
            [
                [1, 'Anna (you)', 50, 0, 1, 1], ['behind', 'Bob', null, 0, 0, 1], ['behind', 'Carol', null, 0, 0, 1],
                [null, 'Xaver', null, null, null, null], [null, 'Yann', null, null, null, null], [null, 'Zoe', null, null, null, null],
            ],
            self::entries($view),
        );
    }

    public function test_FR_COMP_06_the_own_entry_has_no_rank_either_before_the_first_day_counts(): void
    {
        // Monday the 16th of November at one o'clock at night in Vienna: nothing of the week counts yet.
        $this->clock->travelTo(TestData::time('2026-11-16 00:00:00'));
        foreach ([$this->anna, $this->bob, $this->carol] as $user) {
            $this->givenProgress($user, '2026-11-16');
        }

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, 'current');

        self::assertSame('2026-W47', $view->period->key);
        self::assertSame([[null, 'Anna (you)', null, null, null, null], [null, 'Bob', null, null, null, null], [null, 'Carol', null, null, null, null]], self::entries($view));
    }

    public function test_FR_COMP_08_who_is_ahead_is_decided_exactly_and_somebody_equal_is_level(): void
    {
        $dave = $this->givenPlayer('Dave');
        $this->givenFriends($this->anna, $dave);
        // The quarter so far, 47 days. Anna one session less than Bob: the same whole percent, and ahead.
        $this->givenDays($this->anna, '2026-10-01', [...array_fill(0, 46, 6), 5]);
        $this->givenDays($this->bob, '2026-10-01', array_fill(0, 47, 6));
        // Dave is equal to Anna in everything. Carol is far behind.
        $this->givenDays($dave, '2026-10-01', [5, ...array_fill(0, 46, 6)]);
        $this->givenDays($this->carol, '2026-10-01', array_fill(0, 47, 9));

        $bobs = $this->showLeague()->show($this->bob->id, LeaguePeriod::Quarter, 'current');
        $annas = $this->showLeague()->show($this->anna->id, LeaguePeriod::Quarter, 'current');

        self::assertSame([['ahead', 'Anna', null, 0, 47, 47], [2, 'Bob (you)', 50, 0, 47, 47]], self::entries($bobs));
        // Her own rank is one more than the number of friends who are ahead: somebody level does not push her down.
        self::assertSame(
            [[1, 'Anna (you)', 50, 0, 47, 47], ['level', 'Dave', null, 0, 47, 47], ['behind', 'Bob', null, 0, 47, 47], ['behind', 'Carol', null, 0, 47, 47]],
            self::entries($annas),
        );
    }

    public function test_FR_FRND_03_while_a_league_is_not_final_friends_are_not_ranked_against_each_other(): void
    {
        // Otherwise somebody could add accounts of their own as friends, give each a result they choose,
        // and read every day where a real friend stands among them: the friend's reduction, to a few percent.
        $higher = $this->givenPlayer('Higher');
        $lower = $this->givenPlayer('Lower');
        $this->givenFriends($this->anna, $higher);
        $this->givenFriends($this->anna, $lower);
        $this->givenDays($this->anna, '2026-11-16', [12]);
        $this->givenDays($this->carol, '2026-11-16', [12]);
        // Three are ahead of Anna, and far apart from each other. Carol is level with her.
        $this->givenDays($higher, '2026-11-16', [0]);
        $this->givenDays($this->bob, '2026-11-16', [5]);
        $this->givenDays($lower, '2026-11-16', [11]);

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, 'current');

        // By name within the group, not by how far ahead they are. And nobody but the user has a rank.
        self::assertSame(['Bob', 'Higher', 'Lower', 'Anna (you)', 'Carol'], array_column(self::entries($view), 1));
        self::assertSame(['ahead', 'ahead', 'ahead', 4, 'level'], array_column(self::entries($view), 0));
        foreach ($view->entries as $entry) {
            self::assertSame($entry->you, $entry->rank !== null);
            self::assertSame($entry->you, $entry->standing === null);
        }
    }

    public function test_FR_FRND_03_of_a_friend_only_final_days_count_and_the_own_open_days_do_not_move_the_comparison(): void
    {
        // Last week is over. For Anna its first five days are final, the weekend she can still change.
        // For Bob all of it is final. For Carol the first two days.
        $this->givenProgress($this->anna, '2026-11-14');
        $this->givenProgress($this->carol, '2026-11-11');
        $this->givenDays($this->bob, '2026-11-09', [9, 9, 9, 9, 9, 9, 9]);
        $this->givenDays($this->carol, '2026-11-09', [0, 0, 0, 0, 0, 0, 0]);
        $seen = [];
        // She tries her weekend from nothing to as much as can be logged.
        foreach ([[0, 0], [12, 12], [300, 300]] as $weekend) {
            $this->givenDays($this->anna, '2026-11-09', [6, 6, 6, 6, 6, ...$weekend]);
            $seen[] = self::entries($this->showLeague()->show($this->anna->id, LeaguePeriod::Week, '2026-W46'));
        }

        // Her own percentage follows what she logs. What she sees of the others does not move: Carol
        // ahead over her two final days, Bob behind, compared with Anna's five final days (50 %).
        self::assertSame(
            [
                [['ahead', 'Carol', null, 2, 2, 2], [2, 'Anna (you)', 64, 2, 7, 7], ['behind', 'Bob', null, 0, 7, 7]],
                [['ahead', 'Carol', null, 2, 2, 2], [2, 'Anna (you)', 36, 0, 7, 7], ['behind', 'Bob', null, 0, 7, 7]],
                [['ahead', 'Carol', null, 2, 2, 2], [2, 'Anna (you)', -650, 0, 7, 7], ['behind', 'Bob', null, 0, 7, 7]],
            ],
            $seen,
        );
    }

    public function test_FR_COMP_08_a_user_without_a_final_day_has_the_own_figures_and_no_rank(): void
    {
        // Anna has checked in yesterday and today, and none of it is final yet. Bob's yesterday is.
        $this->givenProgress($this->anna, '2026-11-16');
        $this->givenDays($this->anna, '2026-11-16', [6, 6]);
        $this->givenDays($this->bob, '2026-11-16', [0]);

        $entries = self::entries($this->showLeague()->show($this->anna->id, LeaguePeriod::Week, 'current'));

        // Friends with a final day first, by name, with what is shown of them. Nobody is compared.
        self::assertSame([[null, 'Bob', null, 1, 1, 1], [null, 'Carol', null, 0, 0, 1], [null, 'Anna (you)', 50, 0, 2, 2]], $entries);
    }

    public function test_FR_COMP_08_before_the_user_has_a_day_that_counts_nobody_is_ahead_or_behind(): void
    {
        // Xaver is on board since today and has not checked in. His friends have a day that counts.
        $xaver = $this->givenPlayer('Xaver', '2026-11-17');
        $this->givenFriends($xaver, $this->anna);
        $this->givenFriends($xaver, $this->bob);
        $this->givenDays($this->anna, '2026-11-16', [6]);

        $entries = self::entries($this->showLeague()->show($xaver->id, LeaguePeriod::Week, 'current'));

        // His friends by name, with what is shown of them all the time, and no comparison.
        self::assertSame([[null, 'Anna', null, 0, 1, 1], [null, 'Bob', null, 0, 0, 1], [null, 'Xaver (you)', null, null, null, null]], $entries);
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

    public function test_FR_FRND_03_a_final_percentage_of_a_friend_is_shown_only_for_seven_counted_days_or_more(): void
    {
        self::assertSame(7, ShowLeague::MIN_DAYS_FOR_A_PERCENTAGE);
        // Last month. Dave joined on the 29th: three days. Erin on the 25th: seven days.
        $dave = $this->givenPlayer('Dave', '2026-10-29');
        $erin = $this->givenPlayer('Erin', '2026-10-25');
        $this->givenFriends($this->anna, $dave);
        $this->givenFriends($this->anna, $erin);
        $month = CalendarPeriod::fromKey(LeaguePeriod::Month, '2026-10');
        $scored = TestData::time('2026-11-02 23:00:20');
        $this->leagueResults->save(new LeagueResult($this->anna->id, $month, 120, 1860, 3720, 0, 31, 31, 'v1', $scored, $scored, new LeagueAward(3, 3, 100, $scored)));
        $this->leagueResults->save(new LeagueResult($dave->id, $month, 120, 60, 360, 1, 3, 3, 'v1', $scored, $scored));
        $this->leagueResults->save(new LeagueResult($erin->id, $month, 120, 210, 840, 2, 7, 7, 'v1', $scored, $scored));

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Month, '2026-10');

        // A percentage over three days is nearly the figure of a day. Dave has his rank, and no percentage.
        self::assertTrue($view->final);
        self::assertSame([1, 'Dave', null, 1, 3, 3], self::entries($view)[0]);
        self::assertSame([2, 'Erin', 75, 2, 7, 7], self::entries($view)[1]);
        self::assertSame([3, 'Anna (you)', 50, 0, 31, 31], self::entries($view)[2]);
        // His own percentage is his to see, however few days.
        $this->leagueResults->save(new LeagueResult($dave->id, $month, 120, 60, 360, 1, 3, 3, 'v1', $scored, $scored, new LeagueAward(1, 2, 0, $scored)));
        $daves = self::entries($this->showLeague()->show($dave->id, LeaguePeriod::Month, '2026-10'));
        self::assertSame([[1, 'Dave (you)', 83, 1, 3, 3], [2, 'Anna', 50, 0, 31, 31]], $daves);
    }

    public function test_FR_COMP_08_a_period_that_is_over_and_not_ranked_yet_still_shows_who_is_ahead_only(): void
    {
        // Last week: Anna and Bob have their results, Carol's last day is not final yet. Nobody is ranked.
        $this->givenResult($this->anna, 42);
        $this->givenResult($this->bob, 60);
        $this->givenProgress($this->carol, '2026-11-15');
        $this->givenDays($this->carol, '2026-11-09', [0, 0, 0, 0, 0, 0, [3]]);

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, '2026-W46');

        self::assertSame([false, null], [$view->final, $view->award]);
        // Carol's week as far as it is final: six smoke-free days. Her Sunday is not final and not shown.
        self::assertSame([['ahead', 'Carol', null, 6, 6, 6], [2, 'Anna (you)', 50, 0, 7, 7], ['behind', 'Bob', null, 0, 7, 7]], self::entries($view));
    }

    public function test_FR_COMP_08_in_a_final_league_only_stored_results_are_ranked(): void
    {
        // Anna was ranked after the seven days of waiting, without Carol, whose days are still not final.
        $this->givenResult($this->anna, 42, award: new LeagueAward(1, 2, 0, TestData::time('2026-11-17 11:05:00')));
        $this->givenResult($this->bob, 60);
        $this->givenProgress($this->carol, '2026-11-12');
        $this->givenDays($this->carol, '2026-11-09', [0, 0, 0, 0, 0, 0, 0]);

        $view = $this->showLeague()->show($this->anna->id, LeaguePeriod::Week, '2026-W46');

        // A final league is made of final results. Carol has none yet: she is listed without a rank,
        // whatever her days look like so far. The reward is the one that was decided.
        self::assertTrue($view->final);
        self::assertSame([[1, 'Anna (you)', 50, 0, 7, 7], [2, 'Bob', 29, 0, 7, 7], [null, 'Carol', null, null, null, null]], self::entries($view));
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
