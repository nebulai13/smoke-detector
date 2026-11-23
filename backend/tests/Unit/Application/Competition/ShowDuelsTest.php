<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Competition;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\DayLevel;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\DuelNotFound;
use SmokeDetector\Application\Competition\DuelStanding;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Competition\DuelView;
use SmokeDetector\Application\Competition\DuelWinner;
use SmokeDetector\Application\Competition\ShowDuels;
use SmokeDetector\Application\Competition\SideResult;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\DuelsUseCaseTestCase;

/**
 * What a user sees of their duels (SRS FR-COMP-03, 08, 09; FR-FRND-03; HLD §4.8): both sides in
 * percent, never a count, a limit or the baseline of the other one.
 *
 * Both users have a baseline of 12, so 6 sessions on a day are a reduction of 50 %. What is shown is
 * coarse on purpose: whole percent for a duel, one of four levels for a day.
 */
final class ShowDuelsTest extends DuelsUseCaseTestCase
{
    private User $anna;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->anna, $this->bob] = $this->givenTwoFriends();
    }

    public function test_FR_COMP_01_a_proposal_is_seen_by_both_with_nothing_to_show_yet(): void
    {
        $proposal = $this->givenProposal(1, $this->anna, $this->bob, 30);
        $nothingYet = ['from' => null, 'to' => null, 'mine' => null, 'theirs' => null, 'standing' => null, 'outcome' => null, 'xp' => null, 'provisional' => false];

        $annas = $this->showDuels()->list($this->anna->id);
        $bobs = $this->showDuels()->list($this->bob->id);

        self::assertSame([['status' => 'proposed', 'days' => 30, 'challenger' => true, 'opponent' => 'Bob'] + $nothingYet], array_map(self::duelView(...), $annas));
        self::assertSame([['status' => 'proposed', 'days' => 30, 'challenger' => false, 'opponent' => 'Anna'] + $nothingYet], array_map(self::duelView(...), $bobs));
        self::assertSame([$proposal->id, $proposal->createdAt, $proposal->expiresAt], [$annas[0]->id, $annas[0]->createdAt, $annas[0]->expiresAt]);
        self::assertSame([$this->bob->id->value, 'Bob', 'avatar-07'], self::profile($annas[0]->opponent ?? self::fail('No opponent.')));
    }

    public function test_FR_COMP_01_a_proposal_whose_72_hours_are_over_is_shown_as_expired_before_the_cron_job_was_there(): void
    {
        $this->givenProposal(1, $this->anna, $this->bob, 7, '2026-11-14 12:00:01');

        self::assertSame('proposed', $this->showDuels()->list($this->bob->id)[0]->status->value);

        $this->clock->advanceSeconds(1);
        self::assertSame('expired', $this->showDuels()->list($this->bob->id)[0]->status->value);
        self::assertSame('expired', $this->showDuels()->show($this->anna->id, TestData::uuid(1))->duel->status->value);
        // Nothing was written by looking.
        self::assertSame(DuelStatus::Proposed, $this->duels->find(TestData::uuid(1))?->status);
    }

    public function test_FR_COMP_03_a_running_duel_shows_both_sides_over_the_days_that_count_so_far(): void
    {
        // From the 13th to the 19th. Today is the 17th.
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-13');
        // Anna: 7, then 12, a day without anything, a day with 15 logged and no check-in, and today 3, checked in.
        $this->givenDays($this->anna, '2026-11-13', [7, 12, null, [15], 3]);
        // Bob: two smoke-free days, 12, 6, and today 2 without a check-in yet.
        $this->givenDays($this->bob, '2026-11-13', [0, 0, 12, 6, [2]]);

        $annas = $this->showDuels()->list($this->anna->id);
        $bobs = $this->showDuels()->list($this->bob->id);

        // Anna: 7 + 12 + 12 (a day without a check-in counts as the baseline) + 15 (or as what was
        // logged, when that is more) + 3 = 49 of 60: 18.3 %, shown as 18. Bob: 18 of 48, 62.5 %, shown
        // as 63: his today does not count yet. While the duel runs, each sees the own percentage, and
        // of the other only who is ahead: a figure that moves every day could be calculated back.
        self::assertSame(
            [[
                'status' => 'active', 'days' => 7, 'challenger' => true, 'opponent' => 'Bob', 'from' => '2026-11-13', 'to' => '2026-11-19',
                'mine' => [18, 0, 3, 5], 'theirs' => [null, 2, 4, 4], 'standing' => 'behind', 'outcome' => null, 'xp' => null, 'provisional' => true,
            ]],
            array_map(self::duelView(...), $annas),
        );
        self::assertSame(
            [[
                'status' => 'active', 'days' => 7, 'challenger' => false, 'opponent' => 'Anna', 'from' => '2026-11-13', 'to' => '2026-11-19',
                'mine' => [63, 2, 4, 4], 'theirs' => [null, 0, 3, 5], 'standing' => 'ahead', 'outcome' => null, 'xp' => null, 'provisional' => true,
            ]],
            array_map(self::duelView(...), $bobs),
        );
    }

    public function test_FR_COMP_09_the_detail_shows_the_level_of_every_day_that_counts(): void
    {
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-13');
        $this->givenDays($this->anna, '2026-11-13', [7, 12, null, [15], 3]);
        $this->givenDays($this->bob, '2026-11-13', [0, 0, 12, 6, [2]]);

        $detail = $this->showDuels()->show($this->anna->id, TestData::uuid(1));

        self::assertSame(self::duelView($this->showDuels()->list($this->anna->id)[0]), self::duelView($detail->duel));
        self::assertSame(
            [[1, 'under', 'smoke_free'], [2, 'not_under', 'smoke_free'], [3, 'not_under', 'not_under'], [4, 'not_under', 'well_under'], [5, 'well_under', null], [6, null, null], [7, null, null]],
            self::dayStandings($detail),
        );
        // The same days from the other side.
        self::assertSame(
            [[1, 'smoke_free', 'under'], [2, 'smoke_free', 'not_under'], [3, 'not_under', 'not_under'], [4, 'well_under', 'not_under'], [5, null, 'well_under'], [6, null, null], [7, null, null]],
            self::dayStandings($this->showDuels()->show($this->bob->id, TestData::uuid(1))),
        );
    }

    public function test_FR_FRND_03_a_day_is_shown_as_one_of_four_levels_and_never_as_a_number(): void
    {
        self::assertSame(['smoke_free', 'well_under', 'under', 'not_under'], array_map(static fn (DayLevel $level): string => $level->value, DayLevel::cases()));
        // Bob's baseline is 11.9 from the 2nd on. A duel of 30 days from the 5th.
        $this->givenWeeklyBaseline($this->bob, '2026-11-02', 119);
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-05', 30);
        // Against 12.0: nothing, 1, 6 (exactly half), 7, 11, 12 (exactly the baseline), 13, then a day
        // with nothing logged and no check-in, and days without any row.
        $this->givenDays($this->anna, '2026-11-05', [0, 1, 6, 7, 11, 12, 13, [0]]);
        // Against 11.9: 5 is less than half, 6 is more than half.
        $this->givenDays($this->bob, '2026-11-05', [5, 6]);

        $days = self::dayStandings($this->showDuels()->show($this->anna->id, TestData::uuid(1)));

        self::assertSame(
            // Smoke-free is a checked-in day without a session: the day nobody confirmed is not one.
            ['smoke_free', 'well_under', 'well_under', 'under', 'under', 'not_under', 'not_under', 'not_under', 'not_under', 'not_under', 'not_under', 'not_under', null],
            array_slice(array_column($days, 1), 0, 13),
        );
        self::assertSame(['well_under', 'under', 'not_under'], array_slice(array_column($days, 2), 0, 3));
        self::assertCount(30, $days);
    }

    public function test_FR_FRND_03_the_reduction_of_a_duel_is_shown_in_whole_percent_rounded_away_from_zero(): void
    {
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-15');
        // 3 of 24 over two days: 87.5 %. And 27 of 24: -12.5 %.
        $this->givenDays($this->anna, '2026-11-15', [1, 2]);
        $this->givenDays($this->bob, '2026-11-15', [13, 14]);

        self::assertSame([88, 0, 2, 2], self::side($this->showDuels()->list($this->anna->id)[0]->mine));
        self::assertSame([-13, 0, 2, 2], self::side($this->showDuels()->list($this->bob->id)[0]->mine));
    }

    public function test_FR_FRND_03_while_a_duel_runs_the_other_side_is_ahead_level_or_behind_and_has_no_percentage(): void
    {
        self::assertSame(['ahead', 'level', 'behind'], array_map(static fn (DuelStanding $standing): string => $standing->value, DuelStanding::cases()));
        $in = true;
        $out = false;

        // More reduction is ahead: 15 of 36 against 16 of 36.
        self::assertSame(['ahead', 'behind'], $this->standingsWith([[5, $in], [5, $in], [5, $in]], [[5, $in], [5, $in], [6, $in]]));
        // The same reduction: more smoke-free days are ahead.
        self::assertSame(['behind', 'ahead'], $this->standingsWith([[4, $in], [4, $in], [4, $in]], [[0, $in], [6, $in], [6, $in]]));
        // The same reduction and smoke-free days: more check-ins are ahead (a day without one counts as the baseline, 12).
        self::assertSame(['ahead', 'behind'], $this->standingsWith([[12, $in], [12, $in], [6, $in]], [[12, $out], [12, $in], [6, $in]]));
        // Equal on all three.
        self::assertSame(['level', 'level'], $this->standingsWith([[3, $in], [6, $in], [9, $in]], [[9, $in], [6, $in], [3, $in]]));
    }

    public function test_FR_FRND_03_who_is_ahead_is_decided_exactly_also_when_both_show_the_same_whole_percent(): void
    {
        // 30 days from the 18th of October, which ended yesterday. Anna 179 of 360, Bob 180 of 360:
        // 50.3 % and 50.0 %, both shown as 50.
        $this->givenRunning(1, $this->anna, $this->bob, '2026-10-18', 30);
        $this->givenDays($this->anna, '2026-10-18', [...array_fill(0, 29, 6), 5]);
        $this->givenDays($this->bob, '2026-10-18', array_fill(0, 30, 6));

        $annas = $this->showDuels()->list($this->anna->id)[0];
        $bobs = $this->showDuels()->list($this->bob->id)[0];

        self::assertSame([50, 50], [$annas->mine?->reductionPercent, $bobs->mine?->reductionPercent]);
        self::assertSame(['ahead', 'behind'], [$annas->standing?->value, $bobs->standing?->value]);
    }

    public function test_FR_FRND_03_nobody_is_ahead_before_both_have_a_day_that_counts(): void
    {
        // Started today. Anna has checked in, Bob has not.
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-17');
        $this->givenDays($this->anna, '2026-11-17', [0]);

        $annas = $this->showDuels()->list($this->anna->id)[0];
        $bobs = $this->showDuels()->list($this->bob->id)[0];

        self::assertSame([[100, 1, 1, 1], null, null], [self::side($annas->mine), $annas->theirs, $annas->standing]);
        self::assertSame([null, [null, 1, 1, 1], null], [$bobs->mine, self::side($bobs->theirs), $bobs->standing]);
    }

    public function test_FR_COMP_02_before_its_first_day_a_duel_has_no_side_yet(): void
    {
        // Accepted today: it starts tomorrow.
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-18');
        $this->givenDays($this->anna, '2026-11-17', [0]);

        $detail = $this->showDuels()->show($this->anna->id, TestData::uuid(1));

        self::assertSame([null, null, null, true], [$detail->duel->mine, $detail->duel->theirs, $detail->duel->standing, $detail->duel->provisional]);
        self::assertSame(['2026-11-18', '2026-11-24'], [$detail->duel->startsOn?->value, $detail->duel->endsOn?->value]);
        self::assertSame(array_map(static fn (int $day): array => [$day, null, null], range(1, 7)), self::dayStandings($detail));
    }

    public function test_FR_COMP_03_the_first_day_counts_from_its_check_in_and_the_last_day_is_the_last(): void
    {
        // A duel that starts today: nothing counts until today is checked in.
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-17');
        self::assertNull($this->showDuels()->list($this->anna->id)[0]->mine);
        $this->givenDays($this->anna, '2026-11-17', [0]);
        self::assertSame([100, 1, 1, 1], self::side($this->showDuels()->list($this->anna->id)[0]->mine));

        // A duel whose last day was yesterday: seven days count, and the days after it do not.
        $carol = $this->givenNamed('Carol');
        $this->givenFriends($this->anna, $carol);
        $this->givenOnboarded($carol, '2026-11-01');
        $this->givenRunning(2, $carol, $this->anna, '2026-11-10');
        $this->givenDays($carol, '2026-11-10', [6, 6, 6, 6, 6, 6, 6, 40]);

        $carols = $this->showDuels()->show($carol->id, TestData::uuid(2));

        self::assertSame([50, 0, 7, 7], self::side($carols->duel->mine));
        self::assertSame('2026-11-16', $carols->duel->endsOn?->value);
        self::assertCount(7, $carols->days);
        self::assertTrue($carols->duel->provisional);
    }

    public function test_FR_COMP_02_each_side_is_rated_over_its_own_days(): void
    {
        // Bob lives in Auckland and started a day later, on his 14th. His today is the 18th already.
        $bob = $this->givenZoneChange($this->bob, '2026-11-02', 'Pacific/Auckland');
        $this->givenRunning(1, $this->anna, $bob, '2026-11-13', 7, '2026-11-14');
        $this->givenDays($this->anna, '2026-11-13', [6, 6, 6, 6]);
        $this->givenDays($bob, '2026-11-14', [0, 0, 0, 0, [5]]);

        $detail = $this->showDuels()->show($bob->id, TestData::uuid(1));

        // His days 14 to 17 count, his 18th is today and not checked in. Her days 13 to 16 count.
        self::assertSame(['2026-11-14', '2026-11-20'], [$detail->duel->startsOn?->value, $detail->duel->endsOn?->value]);
        self::assertSame([[100, 4, 4, 4], [null, 0, 4, 4], 'ahead'], [self::side($detail->duel->mine), self::side($detail->duel->theirs), $detail->duel->standing?->value]);
        self::assertSame(['2026-11-13', '2026-11-19'], [$this->showDuels()->list($this->anna->id)[0]->startsOn?->value, $this->showDuels()->list($this->anna->id)[0]->endsOn?->value]);
    }

    public function test_FR_PLAN_07_the_baseline_of_a_duel_is_frozen_on_its_first_day(): void
    {
        // The weekly baselines: 8.0 from Monday the 9th, 4.0 from Monday the 16th.
        $this->givenWeeklyBaseline($this->anna, '2026-11-09', 80);
        $this->givenWeeklyBaseline($this->anna, '2026-11-16', 40);
        $this->givenWeeklyBaseline($this->bob, '2026-11-16', 40);
        // Anna started on the 9th itself, Bob on the 15th, the day before his new baseline.
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-09', 30, '2026-11-15');
        $this->givenDays($this->anna, '2026-11-09', [4, 4, 4, 4, 4, 4, 4, 4]);
        $this->givenDays($this->bob, '2026-11-15', [6, 6]);

        $view = $this->showDuels()->list($this->anna->id)[0];

        // Anna against 8.0 on all eight days, also after her baseline went down to 4.0. Bob against 12.0.
        self::assertSame([[50, 0, 8, 8], [null, 0, 2, 2]], [self::side($view->mine), self::side($view->theirs)]);
        self::assertSame([50, 0, 2, 2], self::side($this->showDuels()->list($this->bob->id)[0]->mine));
    }

    public function test_FR_PLAN_07_a_duel_never_scores_against_a_baseline_below_one(): void
    {
        $this->givenWeeklyBaseline($this->anna, '2026-11-09', 4);
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-13');
        $this->givenDays($this->anna, '2026-11-13', [0, 1, 2, null]);

        // Against 1.0 a day: 0 + 1 + 2 + 1 (the day without a check-in) of 4.
        self::assertSame([0, 1, 3, 4], self::side($this->showDuels()->list($this->anna->id)[0]->mine));
        // One session is not under a baseline of 1.0.
        self::assertSame(
            ['smoke_free', 'not_under', 'not_under', 'not_under'],
            array_slice(array_column(self::dayStandings($this->showDuels()->show($this->anna->id, TestData::uuid(1))), 1), 0, 4),
        );
    }

    public function test_FR_COMP_08_a_completed_duel_shows_the_stored_result_to_both(): void
    {
        // Decided on the 12th: Anna 50 %. Bob, against a baseline of 8.0: 50 of 56, 10.7 %, shown as 11.
        $this->givenEnded(1, $this->anna, $this->bob, DuelStatus::Completed, TestData::decision(
            DuelWinner::Challenger, new SideResult(120, 420, 840, 0, 7, 100), new SideResult(80, 500, 560, 2, 6, 20), '2026-11-12 23:00:40',
        ));
        $this->givenDays($this->anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        $this->givenDays($this->bob, '2026-11-03', [0, 0, 5, 12, 12, 12, [9]]);

        $annas = $this->showDuels()->show($this->anna->id, TestData::uuid(1));
        $bobs = $this->showDuels()->show($this->bob->id, TestData::uuid(1));

        self::assertSame(
            [
                'status' => 'completed', 'days' => 7, 'challenger' => true, 'opponent' => 'Bob', 'from' => '2026-11-03', 'to' => '2026-11-09',
                'mine' => [50, 0, 7, 7], 'theirs' => [11, 2, 6, 7], 'standing' => null, 'outcome' => 'won', 'xp' => 100, 'provisional' => false,
            ],
            self::duelView($annas->duel),
        );
        self::assertSame(['lost', 20, [11, 2, 6, 7], [50, 0, 7, 7]], [$bobs->duel->outcome?->value, $bobs->duel->xp, self::side($bobs->duel->mine), self::side($bobs->duel->theirs)]);
        // The days against the baseline the result was scored with: 8.0 for Bob, not his 12.0 of today.
        // His 5 sessions of the third day are more than half of 8.0, and would be less than half of 12.0.
        self::assertSame(
            [[1, 'well_under', 'smoke_free'], [2, 'well_under', 'smoke_free'], [3, 'well_under', 'under'], [4, 'well_under', 'not_under'], [5, 'well_under', 'not_under'], [6, 'well_under', 'not_under'], [7, 'well_under', 'not_under']],
            self::dayStandings($annas),
        );
    }

    public function test_FR_COMP_03_a_draw_is_a_draw_for_both(): void
    {
        $this->givenEnded(1, $this->anna, $this->bob, DuelStatus::Completed, TestData::decision(
            DuelWinner::Draw, new SideResult(120, 420, 840, 0, 7, 50), new SideResult(120, 420, 840, 0, 7, 50),
        ));

        self::assertSame(['draw', 50], [$this->showDuels()->list($this->anna->id)[0]->outcome?->value, $this->showDuels()->list($this->anna->id)[0]->xp]);
        self::assertSame(['draw', 50], [$this->showDuels()->list($this->bob->id)[0]->outcome?->value, $this->showDuels()->list($this->bob->id)[0]->xp]);
    }

    public function test_FR_COMP_01_a_duel_that_ended_without_a_result_shows_no_side(): void
    {
        foreach ([DuelStatus::Declined, DuelStatus::Expired, DuelStatus::Cancelled, DuelStatus::Forfeited] as $index => $status) {
            $this->givenEnded(10 + $index, $this->anna, $this->bob, $status);
        }
        $this->givenDays($this->anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);

        foreach ($this->showDuels()->list($this->anna->id) as $view) {
            self::assertSame([null, null, null, null, false], [$view->mine, $view->theirs, $view->outcome, $view->xp, $view->provisional], $view->status->value);
            self::assertSame([], $this->showDuels()->show($this->anna->id, $view->id)->days, $view->status->value);
        }
    }

    public function test_FR_FRND_03_once_the_two_are_no_friends_any_more_each_keeps_the_own_side_only(): void
    {
        $this->givenEnded(1, $this->anna, $this->bob, DuelStatus::Completed, TestData::decision(
            DuelWinner::Challenger, new SideResult(120, 420, 840, 0, 7, 100), new SideResult(120, 630, 840, 2, 6, 20),
        ));
        $this->givenEnded(2, $this->bob, $this->anna, DuelStatus::Cancelled, null, '2026-11-13');
        $this->givenDays($this->anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        $this->givenDays($this->bob, '2026-11-03', [0, 0, 12, 12, 12, 12, 12]);
        $this->friendLinks->delete($this->anna->id, $this->bob->id);

        $views = $this->showDuels()->list($this->anna->id);
        $detail = $this->showDuels()->show($this->anna->id, TestData::uuid(1));

        // The newer one first. No name and no score of the other one, in neither duel.
        self::assertSame(['cancelled', 'completed'], array_map(static fn (DuelView $view): string => $view->status->value, $views));
        self::assertSame([null, null], [$views[0]->opponent, $views[1]->opponent]);
        self::assertSame(['mine' => [50, 0, 7, 7], 'theirs' => null, 'outcome' => 'won', 'xp' => 100], array_intersect_key(self::duelView($views[1]), ['mine' => 1, 'theirs' => 1, 'outcome' => 1, 'xp' => 1]));
        self::assertSame(array_map(static fn (int $day): array => [$day, 'well_under', null], range(1, 7)), self::dayStandings($detail));
    }

    public function test_FR_FRND_03_a_request_that_is_not_accepted_is_no_friendship(): void
    {
        $this->givenRunning(1, $this->anna, $this->bob);
        $this->givenDays($this->bob, '2026-11-13', [0, 0]);
        // They stopped being friends, and Bob has asked again.
        $this->friendLinks->delete($this->anna->id, $this->bob->id);
        $this->givenRequest($this->bob, $this->anna);

        $view = $this->showDuels()->list($this->anna->id)[0];

        // Not even who is ahead.
        self::assertSame([null, null, null], [$view->opponent, $view->theirs, $view->standing]);
    }

    public function test_FR_ACC_08_a_duel_whose_other_account_is_gone_shows_the_own_side(): void
    {
        $this->givenEnded(1, $this->anna, $this->bob, DuelStatus::Forfeited);
        $this->givenEnded(2, $this->bob, $this->anna, DuelStatus::Completed, TestData::decision(DuelWinner::Opponent, null, new SideResult(120, 420, 840, 0, 7, 100)), '2026-10-20');
        // As the eraser leaves them: without the id, the start and the result of the side that is gone.
        foreach ($this->duels->all() as $duel) {
            $annaChallenged = $duel->challengerId?->equals($this->anna->id) ?? false;
            $this->duels->save(new Duel(
                $duel->id, $annaChallenged ? $duel->challengerId : null, $annaChallenged ? null : $duel->opponentId, $duel->periodDays, $duel->status,
                $duel->createdAt, $duel->expiresAt, $duel->answeredAt, $annaChallenged ? $duel->challengerStart : null, $annaChallenged ? null : $duel->opponentStart, $duel->decision,
            ));
        }
        $this->friendLinks->delete($this->anna->id, $this->bob->id);
        $this->users->delete($this->bob->id);

        $views = $this->showDuels()->list($this->anna->id);

        self::assertSame(
            [
                ['status' => 'forfeited', 'challenger' => true, 'opponent' => null, 'mine' => null, 'theirs' => null, 'outcome' => null, 'xp' => null],
                ['status' => 'completed', 'challenger' => false, 'opponent' => null, 'mine' => [50, 0, 7, 7], 'theirs' => null, 'outcome' => 'won', 'xp' => 100],
            ],
            array_map(
                static fn (DuelView $view): array => array_intersect_key(self::duelView($view), ['status' => 1, 'challenger' => 1, 'opponent' => 1, 'mine' => 1, 'theirs' => 1, 'outcome' => 1, 'xp' => 1]),
                $views,
            ),
        );
    }

    public function test_FR_COMP_01_the_list_holds_the_100_newest_duels(): void
    {
        self::assertSame(100, ShowDuels::MAX_LISTED);
        for ($i = 1; $i <= 101; $i++) {
            $this->givenEnded($i, $this->anna, $this->bob, DuelStatus::Declined, null, sprintf('2026-%02d-%02d', 1 + intdiv($i, 28), 1 + $i % 28));
        }

        $views = $this->showDuels()->list($this->anna->id);

        self::assertCount(100, $views);
        self::assertNotContains(TestData::uuid(1), array_map(static fn (DuelView $view): string => $view->id, $views));
    }

    public function test_NFR_05_only_the_two_of_a_duel_can_look_at_it(): void
    {
        $carol = $this->givenNamed('Carol');
        $this->givenFriends($carol, $this->anna);
        $this->givenRunning(1, $this->anna, $this->bob);

        self::assertSame([], $this->showDuels()->list($carol->id));
        foreach ([TestData::uuid(1), self::unknownId()] as $id) {
            try {
                $this->showDuels()->show($carol->id, $id);
                self::fail('Should have been refused.');
            } catch (DuelNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_FR_ACC_06_a_user_without_a_plan_sees_the_proposals_they_got(): void
    {
        $carol = $this->givenNamed('Carol');
        $this->givenFriends($carol, $this->anna);
        $this->givenProposal(1, $this->anna, $carol);

        self::assertSame(['proposed'], array_map(static fn (DuelView $view): string => $view->status->value, $this->showDuels()->list($carol->id)));
    }

    public function test_FR_ACC_08_an_account_that_was_deleted_in_the_meantime_sees_nothing(): void
    {
        $this->givenRunning(1, $this->anna, $this->bob);
        $this->users->delete($this->anna->id);

        foreach ([fn () => $this->showDuels()->list($this->anna->id), fn () => $this->showDuels()->show($this->anna->id, TestData::uuid(1))] as $look) {
            try {
                $look();
                self::fail('Should have been refused.');
            } catch (AccountNotFound) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->users->lockLog);
    }

    /**
     * A duel that started on the 14th with these three days for each of the two, and what each sees
     * as the standing.
     *
     * @param list<array{int, bool}> $annas The count of each day and whether it was checked in.
     * @param list<array{int, bool}> $bobs
     *
     * @return array{string|null, string|null} Anna's standing and Bob's.
     */
    private function standingsWith(array $annas, array $bobs): array
    {
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-14');
        foreach ([[$this->anna, $annas], [$this->bob, $bobs]] as [$user, $days]) {
            foreach (['2026-11-14', '2026-11-15', '2026-11-16'] as $index => $date) {
                $this->dayRecords->save(new DayRecord($user->id, self::date($date), $days[$index][0], $days[$index][1] ? TestData::time("{$date} 20:00:00") : null));
            }
        }
        $anna = $this->showDuels()->list($this->anna->id)[0];
        $bob = $this->showDuels()->list($this->bob->id)[0];
        // The other one's percentage is never there while the duel runs.
        self::assertSame([null, null], [$anna->theirs?->reductionPercent, $bob->theirs?->reductionPercent]);
        self::assertNotNull($anna->theirs);

        return [$anna->standing?->value, $bob->standing?->value];
    }
}
