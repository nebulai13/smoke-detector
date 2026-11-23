<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Competition;

use LogicException;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\DecideDuels;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\DuelDecision;
use SmokeDetector\Application\Competition\DuelReport;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Competition\DuelWinner;
use SmokeDetector\Application\Competition\SideResult;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Rules\RuleSet;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\DuelsUseCaseTestCase;
use SmokeDetector\Tests\Unit\Domain\Rules\RuleTables;

/**
 * The cron step that decides duels (SRS FR-COMP-03, 04; HLD §4.8): a duel is decided when the last
 * day of both users is final, and a proposal nobody answered expires after 72 hours.
 *
 * Both users have a baseline of 12. For both, every day before the 15th is final. A duel that
 * started on the 3rd ended on the 9th.
 */
final class DecideDuelsTest extends DuelsUseCaseTestCase
{
    private User $anna;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->anna, $this->bob] = $this->givenTwoFriends();
    }

    public function test_FR_COMP_04_a_duel_is_decided_when_the_last_day_of_both_is_final(): void
    {
        $running = $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        $this->givenDays($this->anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        $this->givenDays($this->bob, '2026-11-03', [0, 0, 12, 12, 12, 12, 12]);
        $before = [$this->progressOf($this->anna), $this->progressOf($this->bob)];

        $report = $this->decideDuels()->execute();

        self::assertSame([0, 1, 0], self::report($report));
        // Anna: 42 of 84, a reduction of 50 %. Bob: 60 of 84, about 29 %. Stored as they were scored.
        self::assertEquals(
            [new Duel(
                $running->id, $this->anna->id, $this->bob->id, 7, DuelStatus::Completed, $running->createdAt, $running->expiresAt, $running->answeredAt,
                self::date('2026-11-03'), self::date('2026-11-03'),
                new DuelDecision($this->now(), DuelWinner::Challenger, new SideResult(120, 420, 840, 0, 7, 100), new SideResult(120, 600, 840, 2, 7, 20), 'v1'),
            )],
            $this->duels->all(),
        );
        // 100 XP for the winner, 20 for a duel that was lost but played to its end. Earned today.
        self::assertSame([['duel', '2026-11-17', 100, '2026-11-17 12:00:00']], $this->awards($this->anna));
        self::assertSame([['duel', '2026-11-17', 20, '2026-11-17 12:00:00']], $this->awards($this->bob));
        // 100 XP are level 1. Nothing else of the progress changes.
        self::assertEquals(self::with($before[0], 100, 1), $this->progressOf($this->anna));
        self::assertEquals(self::with($before[1], 20, 0), $this->progressOf($this->bob));
    }

    public function test_FR_GAME_05_the_xp_of_a_duel_adds_to_what_the_user_has_and_can_raise_the_level(): void
    {
        $this->givenProgress($this->bob, '2026-11-15', [3, 5, 1, 4, 1], 250, 1);
        $before = $this->progressOf($this->bob);
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        $this->givenDays($this->bob, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);

        $this->decideDuels()->execute();

        // The opponent wins this one: 250 + 100 = 350 XP, and level 2 starts at 300.
        self::assertSame(DuelWinner::Opponent, $this->duels->find(TestData::uuid(1))?->decision?->winner);
        self::assertEquals(self::with($before, 350, 2), $this->progressOf($this->bob));
        // Anna did nothing in the duel: a duel that was lost and played to its end pays only for a reduction.
        self::assertSame([], $this->awards($this->anna));
    }

    public function test_FR_COMP_03_two_who_did_nothing_draw_and_win_nothing(): void
    {
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        $before = [$this->progressOf($this->anna), $this->progressOf($this->bob)];

        self::assertSame([0, 1, 0], self::report($this->decideDuels()->execute()));

        // The duel is decided and stored: a draw, 84 of 84 for both, and no XP. Otherwise two friends
        // could collect the XP of a draw week after week without doing anything.
        $decision = $this->decisionOf(1);
        self::assertSame(DuelWinner::Draw, $decision->winner);
        self::assertEquals([new SideResult(120, 840, 840, 0, 0, 0), new SideResult(120, 840, 840, 0, 0, 0)], [$decision->challenger, $decision->opponent]);
        self::assertSame([[], []], [$this->awards($this->anna), $this->awards($this->bob)]);
        self::assertEquals($before, [$this->progressOf($this->anna), $this->progressOf($this->bob)]);
    }

    public function test_FR_COMP_03_the_xp_of_a_duel_is_paid_to_a_side_only_for_a_reduction_above_zero(): void
    {
        // Anna smoked exactly her baseline and checked in every day. Bob smoked more than his.
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        $this->givenDays($this->anna, '2026-11-03', [12, 12, 12, 12, 12, 12, 12]);
        $this->givenDays($this->bob, '2026-11-03', [14, 14, 14, 14, 14, 14, 12]);

        $this->decideDuels()->execute();

        // Anna wins the duel, and that is stored. Neither is paid.
        $decision = $this->decisionOf(1);
        self::assertSame(DuelWinner::Challenger, $decision->winner);
        self::assertEquals([new SideResult(120, 840, 840, 0, 7, 0), new SideResult(120, 960, 840, 0, 7, 0)], [$decision->challenger, $decision->opponent]);
        self::assertSame([[], []], [$this->awards($this->anna), $this->awards($this->bob)]);
    }

    public function test_FR_COMP_03_the_smallest_reduction_is_a_reduction(): void
    {
        // Bob's baseline is 1.3 on the first day of the duel: 9.1 sessions in seven days. He has 9.
        $this->givenWeeklyBaseline($this->bob, '2026-11-03', 13);
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        $this->givenDays($this->anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        $this->givenDays($this->bob, '2026-11-03', [2, 2, 1, 1, 1, 1, 1]);

        $this->decideDuels()->execute();

        // A tenth of a session saved: he lost against Anna's 50 %, and gets the XP of a duel played to its end.
        self::assertEquals(new SideResult(13, 90, 91, 0, 7, 20), $this->decisionOf(1)->opponent);
        self::assertSame([['duel', '2026-11-17', 20, '2026-11-17 12:00:00']], $this->awards($this->bob));
    }

    public function test_FR_COMP_03_a_day_without_a_check_in_counts_as_the_baseline_or_as_what_was_logged_when_that_is_more(): void
    {
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        // A day without anything, a day with 20 logged and never checked in, a day with 5 logged and never checked in.
        $this->givenDays($this->anna, '2026-11-03', [6, 6, 6, null, [20], [5], 0]);
        $this->givenDays($this->bob, '2026-11-03', [12, 12, 12, 12, 12, 12, 12]);

        $this->decideDuels()->execute();

        // 18 + 12 + 20 + 12 + 0 = 62 of 84.
        self::assertEquals(new SideResult(120, 620, 840, 1, 4, 100), $this->duels->find(TestData::uuid(1))?->decision?->challenger);
    }

    public function test_FR_COMP_03_with_the_same_reduction_more_smoke_free_days_win(): void
    {
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        // Both 60 of 84. Bob has two smoke-free days, Anna one.
        $this->givenDays($this->anna, '2026-11-03', [0, 6, 6, 12, 12, 12, 12]);
        $this->givenDays($this->bob, '2026-11-03', [0, 0, 12, 12, 12, 12, 12]);

        $this->decideDuels()->execute();

        $decision = $this->decisionOf(1);
        self::assertSame(DuelWinner::Opponent, $decision->winner);
        self::assertEquals([new SideResult(120, 600, 840, 1, 7, 20), new SideResult(120, 600, 840, 2, 7, 100)], [$decision->challenger, $decision->opponent]);
    }

    public function test_FR_COMP_03_with_the_same_reduction_and_smoke_free_days_more_check_ins_win(): void
    {
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        // Both 84 of 84 and no smoke-free day. Bob missed one check-in.
        $this->givenDays($this->anna, '2026-11-03', [12, 12, 12, 12, 12, 12, 12]);
        $this->givenDays($this->bob, '2026-11-03', [12, 12, 12, null, 12, 12, 12]);

        $this->decideDuels()->execute();

        $decision = $this->decisionOf(1);
        self::assertSame(DuelWinner::Challenger, $decision->winner);
        self::assertSame([7, 6], [$decision->challenger?->checkIns, $decision->opponent?->checkIns]);
    }

    public function test_FR_COMP_03_equal_on_all_three_is_a_draw_and_both_get_the_xp_of_a_draw(): void
    {
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        $this->givenDays($this->anna, '2026-11-03', [0, 6, 6, 12, 12, 12, 12]);
        $this->givenDays($this->bob, '2026-11-03', [12, 12, 12, 12, 6, 6, 0]);

        $this->decideDuels()->execute();

        $decision = $this->decisionOf(1);
        self::assertSame(DuelWinner::Draw, $decision->winner);
        self::assertSame([50, 50], [$decision->challenger?->xp, $decision->opponent?->xp]);
        self::assertSame([[['duel', '2026-11-17', 50, '2026-11-17 12:00:00']], [['duel', '2026-11-17', 50, '2026-11-17 12:00:00']]], [$this->awards($this->anna), $this->awards($this->bob)]);
    }

    public function test_FR_COMP_03_reductions_are_compared_exactly_not_as_rounded_percentages(): void
    {
        // Anna against 12.0, Bob against 11.9. Both smoked 6 a day. 50.00 % against 49.58 %: both
        // are "50 %" on a screen without decimals, and Anna wins all the same.
        $this->givenWeeklyBaseline($this->bob, '2026-11-02', 119);
        $this->givenRunning(1, $this->bob, $this->anna, '2026-11-03');
        $this->givenDays($this->anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        $this->givenDays($this->bob, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);

        $this->decideDuels()->execute();

        $decision = $this->decisionOf(1);
        self::assertSame(DuelWinner::Opponent, $decision->winner);
        self::assertEquals(new SideResult(119, 420, 833, 0, 7, 20), $decision->challenger);
    }

    public function test_FR_PLAN_07_each_side_is_scored_against_the_baseline_of_its_first_day(): void
    {
        // Anna's baseline went down to 6.0 on Monday the 9th, the last day of her duel. Bob's went to
        // 6.0 on the 4th, the first day of his.
        $this->givenWeeklyBaseline($this->anna, '2026-11-09', 60);
        $this->givenWeeklyBaseline($this->bob, '2026-11-04', 60);
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03', 7, '2026-11-04');
        $this->givenDays($this->anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        $this->givenDays($this->bob, '2026-11-04', [6, 6, 6, 6, 6, 6, 6]);

        $this->decideDuels()->execute();

        $decision = $this->decisionOf(1);
        // Anna against 12.0 on all seven days: 50 %. Bob against 6.0: 0 %, and so no XP.
        self::assertEquals([new SideResult(120, 420, 840, 0, 7, 100), new SideResult(60, 420, 420, 0, 7, 0)], [$decision->challenger, $decision->opponent]);
    }

    public function test_FR_PLAN_07_no_side_is_scored_against_a_baseline_below_one(): void
    {
        $this->givenWeeklyBaseline($this->anna, '2026-11-02', 3);
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        $this->givenDays($this->anna, '2026-11-03', [0, 0, 0, 0, 0, 0, 1]);

        $this->decideDuels()->execute();

        // Against 1.0 a day: 1 of 7.
        self::assertEquals(new SideResult(10, 10, 70, 6, 7, 100), $this->duels->find(TestData::uuid(1))?->decision?->challenger);
    }

    public function test_FR_COMP_02_each_side_is_scored_over_its_own_days_and_earns_on_its_own_today(): void
    {
        // Bob lives in Auckland, where it is the 18th already, and started a day later than Anna.
        $bob = $this->givenZoneChange($this->bob, '2026-11-02', 'Pacific/Auckland');
        $this->givenRunning(1, $this->anna, $bob, '2026-11-03', 7, '2026-11-04');
        $this->givenDays($this->anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6, 0]);
        $this->givenDays($bob, '2026-11-03', [0, 12, 12, 12, 12, 12, 12, 0]);

        $this->decideDuels()->execute();

        // Her 10th and his 3rd are not days of the duel: he has one smoke-free day, his 10th.
        $decision = $this->decisionOf(1);
        self::assertEquals([new SideResult(120, 420, 840, 0, 7, 100), new SideResult(120, 720, 840, 1, 7, 20)], [$decision->challenger, $decision->opponent]);
        self::assertSame([['duel', '2026-11-17', 100, '2026-11-17 12:00:00']], $this->awards($this->anna));
        self::assertSame([['duel', '2026-11-18', 20, '2026-11-17 12:00:00']], $this->awards($bob));
    }

    public function test_FR_COMP_04_a_duel_waits_while_the_last_day_of_one_of_the_two_is_not_final(): void
    {
        // The 14th is final for both, the 15th is not. One duel ended on the 14th for both, the other
        // on the 15th for Bob.
        $carol = $this->givenNamed('Carol');
        $this->givenFriends($this->anna, $carol);
        $this->givenOnboarded($carol, '2026-11-01', '2026-11-15');
        $done = $this->givenRunning(1, $this->anna, $carol, '2026-11-08');
        $waiting = $this->givenRunning(2, $this->anna, $this->bob, '2026-11-08', 7, '2026-11-09');

        $report = $this->decideDuels()->execute();

        self::assertSame([0, 1, 0], self::report($report));
        self::assertSame(DuelStatus::Completed, $this->duels->find($done->id)?->status);
        self::assertEquals($waiting, $this->duels->find($waiting->id));
        self::assertSame([], $this->awards($this->bob));
    }

    public function test_FR_GAME_01_a_reward_of_nothing_is_no_entry_in_the_ledger(): void
    {
        $table = RuleTables::v1Array();
        self::assertIsArray($table['xp'] ?? null);
        $table['xp']['duelLost'] = 0;
        $this->rules = RuleSet::fromArray($table);
        $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        $this->givenDays($this->anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        // Bob has a reduction and loses: he would get the XP of a lost duel, which this table sets to 0.
        $this->givenDays($this->bob, '2026-11-03', [10, 10, 10, 10, 10, 10, 10]);
        $before = $this->progressOf($this->bob);

        $this->decideDuels()->execute();

        self::assertSame(0, $this->duels->find(TestData::uuid(1))?->decision?->opponent?->xp);
        self::assertSame([], $this->awards($this->bob));
        self::assertEquals($before, $this->progressOf($this->bob));
        self::assertCount(1, $this->awards($this->anna));
    }

    public function test_FR_COMP_01_a_proposal_nobody_answered_expires_after_72_hours(): void
    {
        $carol = $this->givenNamed('Carol');
        $dave = $this->givenNamed('Dave');
        // 72 hours ago to the second, a second less, and long ago.
        $due = $this->givenProposal(1, $this->anna, $this->bob, 7, '2026-11-14 12:00:00');
        $open = $this->givenProposal(2, $this->anna, $carol, 7, '2026-11-14 12:00:01');
        $this->givenProposal(3, $dave, $this->anna, 30, '2026-11-01 08:00:00');

        $report = $this->decideDuels()->execute();

        self::assertSame([2, 0, 0], self::report($report));
        // Only the status changes: nobody answered.
        self::assertEquals(
            new Duel($due->id, $this->anna->id, $this->bob->id, 7, DuelStatus::Expired, $due->createdAt, $due->expiresAt),
            $this->duels->find($due->id),
        );
        self::assertEquals($open, $this->duels->find($open->id));
        self::assertSame(DuelStatus::Expired, $this->duels->find(TestData::uuid(3))?->status);
        // A second run finds nothing to do.
        self::assertSame([0, 0, 0], self::report($this->decideDuels()->execute()));
    }

    public function test_FR_ACC_08_a_proposal_whose_other_account_is_gone_is_deleted_and_stops_nothing(): void
    {
        // Account deletion deletes proposals. If one is left all the same (the foreign key only takes
        // the id out), the step must not fail at it every minute: nobody can answer it any more.
        $this->duels->save(new Duel(TestData::uuid(1), $this->anna->id, null, 7, DuelStatus::Proposed, TestData::time('2026-11-01 08:00:00'), TestData::time('2026-11-04 08:00:00')));
        $this->duels->save(new Duel(TestData::uuid(2), null, $this->bob->id, 7, DuelStatus::Proposed, TestData::time('2026-11-01 08:00:00'), TestData::time('2026-11-04 08:00:00')));
        $due = $this->givenProposal(3, $this->anna, $this->bob, 7, '2026-11-02 08:00:00');

        $report = $this->decideDuels()->execute();

        self::assertSame([3, 0, 0], self::report($report));
        self::assertEquals(
            [new Duel($due->id, $this->anna->id, $this->bob->id, 7, DuelStatus::Expired, $due->createdAt, $due->expiresAt)],
            $this->duels->all(),
        );
        self::assertSame([], $this->logger->records);
    }

    public function test_NFR_01_a_duel_that_changed_while_the_step_waited_for_the_accounts_is_left_alone(): void
    {
        $proposal = $this->givenProposal(1, $this->anna, $this->bob, 7, '2026-11-14 12:00:00');
        // While the step waits, the proposal is taken back.
        $this->users->beforeLock = fn () => $this->duels->delete($proposal->id);

        self::assertSame([0, 0, 0], self::report($this->decideDuels()->execute()));
        self::assertSame([], $this->duels->all());

        $running = $this->givenRunning(2, $this->anna, $this->bob, '2026-11-03');
        // While the step waits, the two stop being friends and the duel is cancelled.
        $cancelled = new Duel(
            $running->id, $running->challengerId, $running->opponentId, 7, DuelStatus::Cancelled, $running->createdAt, $running->expiresAt,
            $running->answeredAt, $running->challengerStart, $running->opponentStart,
        );
        $this->users->beforeLock = fn () => $this->duels->save($cancelled);

        self::assertSame([0, 0, 0], self::report($this->decideDuels()->execute()));
        self::assertEquals([$cancelled], $this->duels->all());
        self::assertSame([[], []], [$this->awards($this->anna), $this->awards($this->bob)]);
        self::assertSame([], $this->logger->records);
    }

    public function test_NFR_01_every_duel_is_decided_with_both_accounts_locked_in_the_order_of_their_ids(): void
    {
        $this->givenRunning(1, $this->bob, $this->anna, '2026-11-03');
        $carol = $this->givenNamed('Carol');
        $this->givenProposal(2, $carol, $this->anna, 7, '2026-11-01 08:00:00');

        $this->decideDuels()->execute();

        // First the proposal that expires, then the duel that is decided.
        self::assertSame([$this->anna->id->value, $carol->id->value, $this->anna->id->value, $this->bob->id->value], $this->users->lockLog);
    }

    public function test_NFR_01_a_duel_that_cannot_be_decided_does_not_stop_the_others_and_is_reported(): void
    {
        // Damaged data: Carol has progress and no plan.
        $carol = $this->givenNamed('Carol');
        $this->progressRecords->save(new ProgressRecord(
            $carol->id, UserProgress::initial(), 0, self::date('2026-11-15'), TestData::time('2026-11-17 23:00:00'), TestData::time('2026-11-22 23:00:00'),
        ));
        $damaged = $this->givenRunning(1, $this->anna, $carol, '2026-11-03');
        $this->givenRunning(2, $this->anna, $this->bob, '2026-11-04');
        $before = $this->progressOf($this->anna);

        $report = $this->decideDuels()->execute();

        self::assertSame([0, 1, 1], self::report($report));
        // Nothing of the damaged duel is stored, for neither side: a decision is one transaction.
        self::assertEquals($damaged, $this->duels->find($damaged->id));
        self::assertSame(DuelStatus::Completed, $this->duels->find(TestData::uuid(2))?->status);
        // Anna and Bob both did nothing: a draw, which pays nothing without a reduction.
        self::assertSame(DuelWinner::Draw, $this->decisionOf(2)->winner);
        self::assertSame([], $this->awards($this->anna));
        self::assertEquals($before, $this->progressOf($this->anna));
        // The log says that it happened and what kind of failure it was, and nothing about whom.
        self::assertCount(1, $this->logger->records);
        self::assertSame(['level' => 'error', 'message' => 'A duel could not be decided.'], array_intersect_key($this->logger->records[0], ['level' => 1, 'message' => 1]));
        // A side without a plan cannot be scored: the same kind of failure as in `days:finalize`.
        self::assertSame(['exception' => LogicException::class], $this->logger->records[0]['context']);
        foreach ([$this->anna->id->value, $carol->id->value, $damaged->id] as $id) {
            self::assertStringNotContainsString($id, $this->logger->text());
        }
    }

    public function test_NFR_01_one_run_takes_fifty_proposals_and_fifty_duels_and_the_next_run_the_rest(): void
    {
        self::assertSame(50, DecideDuels::BATCH_SIZE);
        for ($i = 1; $i <= 51; $i++) {
            $friend = $this->givenUser("friend{$i}@example.org");
            $this->givenOnboarded($friend, '2026-11-01', '2026-11-15');
            $this->givenRunning($i, $this->anna, $friend, '2026-11-03');
            $this->givenProposal(100 + $i, $friend, $this->anna, 7, '2026-11-01 08:00:00');
        }

        self::assertSame([50, 50, 0], self::report($this->decideDuels()->execute()));
        self::assertSame([1, 1, 0], self::report($this->decideDuels()->execute()));
        self::assertSame([0, 0, 0], self::report($this->decideDuels()->execute()));
    }

    /**
     * @return array{int, int, int} Expired, decided, failed.
     */
    private static function report(DuelReport $report): array
    {
        return [$report->expired, $report->decided, $report->failed];
    }

    private function decisionOf(int $number): DuelDecision
    {
        $duel = $this->duels->find(TestData::uuid($number)) ?? self::fail('There is no such duel.');

        return $duel->decision ?? self::fail('The duel is not decided.');
    }

    private function progressOf(User $user): ProgressRecord
    {
        return $this->progressRecords->find($user->id) ?? self::fail('The user has no progress.');
    }

    /**
     * The same progress with another XP total and level.
     */
    private static function with(ProgressRecord $record, int $totalXp, int $level): ProgressRecord
    {
        return new ProgressRecord(
            $record->userId,
            new UserProgress($record->progress->streaks, $totalXp),
            $level,
            $record->nextDay,
            $record->finalizeDueAt,
            $record->baselineDueAt,
        );
    }
}
