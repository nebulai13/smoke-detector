<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Competition;

use InvalidArgumentException;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\DuelExists;
use SmokeDetector\Application\Competition\DuelInvitations;
use SmokeDetector\Application\Competition\DuelLimitReached;
use SmokeDetector\Application\Competition\DuelNotFound;
use SmokeDetector\Application\Competition\DuelNotOpen;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Friends\FriendNotFound;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\DuelsUseCaseTestCase;

/**
 * Proposing and answering a duel (SRS FR-COMP-01, 02, 05; HLD §4.8): a user challenges a friend
 * for 7, 30, 90 or 180 days, and the friend has 72 hours to accept.
 */
final class DuelInvitationsTest extends DuelsUseCaseTestCase
{
    private User $anna;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->anna, $this->bob] = $this->givenTwoFriends();
    }

    public function test_FR_COMP_01_a_proposal_waits_72_hours_for_the_friend(): void
    {
        self::assertSame([7, 30, 90, 180], Duel::PERIODS);
        self::assertSame(72, Duel::OPEN_FOR_HOURS);

        $duel = $this->duelInvitations()->propose($this->anna->id, $this->bob->id, 30);

        self::assertEquals(
            new Duel($duel->id, $this->anna->id, $this->bob->id, 30, DuelStatus::Proposed, $this->now(), $this->now()->modify('+72 hours')),
            $duel,
        );
        self::assertEquals([$duel], $this->duels->all());
        self::assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $duel->id);
    }

    public function test_FR_COMP_01_every_period_of_the_requirement_can_be_proposed_and_no_other(): void
    {
        foreach (Duel::PERIODS as $index => $days) {
            $friend = $this->givenUser("friend{$index}@example.org");
            $this->givenFriends($this->anna, $friend);
            $this->givenOnboarded($friend, '2026-11-01');
            self::assertSame($days, $this->duelInvitations()->propose($friend->id, $this->anna->id, $days)->periodDays);
        }

        foreach ([0, 1, 6, 8, 14, 31, 365, -7] as $days) {
            try {
                $this->duelInvitations()->propose($this->anna->id, $this->bob->id, $days);
                self::fail("{$days} days should have been refused.");
            } catch (InvalidArgumentException $refused) {
                self::assertSame('A duel runs for 7, 30, 90 or 180 days.', $refused->getMessage());
            }
        }
        self::assertCount(4, $this->duels->all());
    }

    public function test_FR_COMP_01_only_a_friend_can_be_challenged(): void
    {
        $asked = $this->givenUser('asked@example.org');
        $asking = $this->givenUser('asking@example.org');
        $stranger = $this->givenUser('stranger@example.org');
        $this->givenRequest($this->anna, $asked);
        $this->givenRequest($asking, $this->anna);
        $nobody = TestData::userId(999999);

        foreach ([$asked->id, $asking->id, $stranger->id, $nobody, $this->anna->id] as $noFriend) {
            try {
                $this->duelInvitations()->propose($this->anna->id, $noFriend, 7);
                self::fail('Should have been refused.');
            } catch (FriendNotFound) {
                self::assertSame([], $this->duels->all());
            }
        }
    }

    public function test_FR_COMP_01_a_user_without_a_plan_cannot_challenge_but_can_be_challenged(): void
    {
        $carol = $this->givenUser('carol@example.org');
        $this->givenFriends($this->anna, $carol);

        // Carol has not done the onboarding. She has nothing a duel could rate.
        try {
            $this->duelInvitations()->propose($carol->id, $this->anna->id, 7);
            self::fail('Should have been refused.');
        } catch (PlanRequired) {
            self::assertSame([], $this->duels->all());
        }
        // Anna can ask her all the same: Carol needs the plan to accept.
        self::assertSame(DuelStatus::Proposed, $this->duelInvitations()->propose($this->anna->id, $carol->id, 7)->status);
    }

    public function test_FR_COMP_05_two_friends_have_one_open_duel_whoever_proposed_it(): void
    {
        $proposal = $this->givenProposal(1, $this->bob, $this->anna);

        foreach ([[$this->anna, $this->bob], [$this->bob, $this->anna]] as [$challenger, $opponent]) {
            try {
                $this->duelInvitations()->propose($challenger->id, $opponent->id, 30);
                self::fail('Should have been refused.');
            } catch (DuelExists) {
                self::assertEquals([$proposal], $this->duels->all());
            }
        }

        // A running duel is open too.
        $this->duelInvitations()->accept($this->anna->id, $proposal->id);
        $this->expectException(DuelExists::class);
        $this->duelInvitations()->propose($this->anna->id, $this->bob->id, 30);
    }

    public function test_FR_COMP_05_a_duel_that_is_over_does_not_stand_in_the_way_of_a_new_one(): void
    {
        foreach ([DuelStatus::Declined, DuelStatus::Expired, DuelStatus::Cancelled, DuelStatus::Completed, DuelStatus::Forfeited] as $index => $status) {
            $this->givenEnded(10 + $index, $this->anna, $this->bob, $status, $status === DuelStatus::Completed ? TestData::decision() : null);
        }

        self::assertSame(DuelStatus::Proposed, $this->duelInvitations()->propose($this->bob->id, $this->anna->id, 7)->status);
    }

    public function test_FR_COMP_01_a_proposal_whose_72_hours_are_over_makes_room_and_is_marked_as_expired(): void
    {
        // Made 72 hours ago to the second. The cron job has not been there yet.
        $old = $this->givenProposal(1, $this->bob, $this->anna, 7, '2026-11-14 12:00:00');

        $new = $this->duelInvitations()->propose($this->anna->id, $this->bob->id, 7);

        self::assertSame(DuelStatus::Expired, $this->duels->find($old->id)?->status);
        self::assertSame(DuelStatus::Proposed, $this->duels->find($new->id)?->status);
        self::assertCount(2, $this->duels->all());
    }

    public function test_FR_COMP_05_a_user_has_at_most_five_open_duels_and_proposals_from_others_do_not_count(): void
    {
        self::assertSame(5, DuelInvitations::MAX_OPEN);
        $friends = [];
        for ($i = 0; $i < 12; $i++) {
            $friends[$i] = $this->givenUser("friend{$i}@example.org");
            $this->givenFriends($this->anna, $friends[$i]);
            $this->givenOnboarded($friends[$i], '2026-11-01');
        }
        // Two running duels (one of them proposed by the friend), two proposals of her own: four.
        $this->givenRunning(1, $this->anna, $friends[0]);
        $this->givenRunning(2, $friends[1], $this->anna);
        $this->givenProposal(3, $this->anna, $friends[2]);
        $this->givenProposal(4, $this->anna, $friends[3]);
        // What does not count: proposals from others, a proposal of her own that has expired, duels that are over.
        $this->givenProposal(5, $friends[4], $this->anna);
        $this->givenProposal(6, $friends[5], $this->anna);
        $this->givenProposal(7, $this->anna, $friends[6], 7, '2026-11-14 12:00:00');
        $this->givenEnded(8, $this->anna, $friends[7], DuelStatus::Completed, TestData::decision());
        $this->givenEnded(9, $this->anna, $friends[8], DuelStatus::Cancelled);

        // The fifth.
        $this->duelInvitations()->propose($this->anna->id, $friends[9]->id, 7);

        $stored = $this->duels->all();
        try {
            $this->duelInvitations()->propose($this->anna->id, $friends[10]->id, 7);
            self::fail('Should have been refused.');
        } catch (DuelLimitReached) {
            self::assertEquals($stored, $this->duels->all());
        }
        // Accepting is refused, too: it would be her sixth.
        try {
            $this->duelInvitations()->accept($this->anna->id, TestData::uuid(5));
            self::fail('Should have been refused.');
        } catch (DuelLimitReached) {
            self::assertEquals($stored, $this->duels->all());
        }
        // The friend is not limited by her duels: he can still challenge her.
        self::assertSame(DuelStatus::Proposed, $this->duelInvitations()->propose($friends[11]->id, $this->anna->id, 7)->status);
    }

    public function test_FR_COMP_05_with_four_open_duels_a_proposal_can_be_accepted(): void
    {
        $friends = [];
        for ($i = 0; $i < 5; $i++) {
            $friends[$i] = $this->givenUser("friend{$i}@example.org");
            $this->givenFriends($this->anna, $friends[$i]);
            $this->givenOnboarded($friends[$i], '2026-11-01');
        }
        $this->givenRunning(1, $this->anna, $friends[0]);
        $this->givenRunning(2, $friends[1], $this->anna);
        $this->givenProposal(3, $this->anna, $friends[2]);
        $this->givenProposal(4, $this->anna, $friends[3]);
        $this->givenProposal(5, $friends[4], $this->anna);

        self::assertSame(DuelStatus::Active, $this->duelInvitations()->accept($this->anna->id, TestData::uuid(5))->status);
    }

    public function test_FR_COMP_02_an_accepted_duel_starts_on_the_next_local_day_of_each_of_the_two(): void
    {
        // Bob lives in Auckland, 13 hours ahead: it is one o'clock at night on the 18th there.
        $bob = $this->givenZoneChange($this->bob, '2026-11-02', 'Pacific/Auckland');
        $proposal = $this->givenProposal(1, $this->anna, $bob, 30, '2026-11-17 09:00:00');

        $duel = $this->duelInvitations()->accept($bob->id, $proposal->id);

        self::assertEquals(
            new Duel(
                $proposal->id, $this->anna->id, $bob->id, 30, DuelStatus::Active, $proposal->createdAt, $proposal->expiresAt, $this->now(),
                self::date('2026-11-18'), self::date('2026-11-19'),
            ),
            $duel,
        );
        self::assertEquals([$duel], $this->duels->all());
    }

    public function test_FR_COMP_01_only_the_one_who_was_challenged_answers_and_only_within_the_72_hours(): void
    {
        $proposal = $this->givenProposal(1, $this->anna, $this->bob, 7, '2026-11-14 12:00:01');
        $answers = [
            fn (User $user) => $this->duelInvitations()->accept($user->id, $proposal->id),
            fn (User $user) => $this->duelInvitations()->decline($user->id, $proposal->id),
        ];

        // The challenger cannot answer for the friend.
        foreach ($answers as $answer) {
            $this->assertNotOpen(fn () => $answer($this->anna));
        }
        // One second later the 72 hours are over.
        $this->clock->advanceSeconds(1);
        foreach ($answers as $answer) {
            $this->assertNotOpen(fn () => $answer($this->bob));
        }
        self::assertEquals([$proposal], $this->duels->all());
    }

    public function test_FR_COMP_01_a_duel_that_is_not_a_proposal_any_more_cannot_be_answered_or_taken_back(): void
    {
        $ended = [];
        foreach ([DuelStatus::Active, DuelStatus::Declined, DuelStatus::Expired, DuelStatus::Cancelled, DuelStatus::Completed, DuelStatus::Forfeited] as $index => $status) {
            $ended[] = $this->givenEnded(10 + $index, $this->anna, $this->bob, $status, $status === DuelStatus::Completed ? TestData::decision() : null);
        }

        foreach ($ended as $duel) {
            $this->assertNotOpen(fn () => $this->duelInvitations()->accept($this->bob->id, $duel->id));
            $this->assertNotOpen(fn () => $this->duelInvitations()->decline($this->bob->id, $duel->id));
            $this->assertNotOpen(fn () => $this->duelInvitations()->cancel($this->anna->id, $duel->id));
        }
        self::assertEquals($ended, $this->duels->all());
    }

    public function test_FR_COMP_02_accepting_needs_a_plan(): void
    {
        $carol = $this->givenUser('carol@example.org');
        $this->givenFriends($this->anna, $carol);
        $proposal = $this->givenProposal(1, $this->anna, $carol);

        try {
            $this->duelInvitations()->accept($carol->id, $proposal->id);
            self::fail('Should have been refused.');
        } catch (PlanRequired) {
            self::assertEquals([$proposal], $this->duels->all());
        }
        // Declining does not.
        $this->duelInvitations()->decline($carol->id, $proposal->id);
        self::assertSame(DuelStatus::Declined, $this->duels->find($proposal->id)?->status);
    }

    public function test_FR_COMP_01_a_declined_duel_stays_in_the_list_as_declined(): void
    {
        $proposal = $this->givenProposal(1, $this->anna, $this->bob);

        $this->duelInvitations()->decline($this->bob->id, $proposal->id);

        self::assertEquals(
            [new Duel($proposal->id, $this->anna->id, $this->bob->id, 7, DuelStatus::Declined, $proposal->createdAt, $proposal->expiresAt, $this->now())],
            $this->duels->all(),
        );
    }

    public function test_FR_COMP_01_the_challenger_takes_an_open_proposal_back_and_it_is_gone(): void
    {
        $proposal = $this->givenProposal(1, $this->anna, $this->bob, 7, '2026-11-14 12:00:01');
        $carol = $this->givenUser('carol@example.org');
        $other = $this->givenProposal(2, $this->anna, $carol);

        // Not by the one who was challenged: that is declining.
        $this->assertNotOpen(fn () => $this->duelInvitations()->cancel($this->bob->id, $proposal->id));

        $this->duelInvitations()->cancel($this->anna->id, $proposal->id);

        self::assertEquals([$other], $this->duels->all());
    }

    public function test_FR_COMP_01_a_proposal_whose_72_hours_are_over_cannot_be_taken_back(): void
    {
        $proposal = $this->givenProposal(1, $this->anna, $this->bob, 7, '2026-11-14 12:00:00');

        $this->assertNotOpen(fn () => $this->duelInvitations()->cancel($this->anna->id, $proposal->id));

        self::assertEquals([$proposal], $this->duels->all());
    }

    public function test_NFR_05_a_duel_of_other_users_and_an_id_nobody_has_are_answered_alike(): void
    {
        $carol = $this->givenUser('carol@example.org');
        $this->givenOnboarded($carol, '2026-11-01');
        $proposal = $this->givenProposal(1, $this->anna, $this->bob);

        foreach ([$proposal->id, self::unknownId()] as $id) {
            foreach ([
                fn () => $this->duelInvitations()->accept($carol->id, $id),
                fn () => $this->duelInvitations()->decline($carol->id, $id),
                fn () => $this->duelInvitations()->cancel($carol->id, $id),
            ] as $action) {
                try {
                    $action();
                    self::fail('Should have been refused.');
                } catch (DuelNotFound) {
                    self::assertEquals([$proposal], $this->duels->all());
                }
            }
        }
    }

    public function test_NFR_05_a_duel_whose_other_account_is_gone_belongs_to_the_one_who_is_left_and_to_nobody_else(): void
    {
        $carol = $this->givenUser('carol@example.org');
        $this->givenOnboarded($carol, '2026-11-01');
        // As the eraser leaves a decided duel: once without the opponent, once without the challenger.
        $withoutOpponent = new Duel(TestData::uuid(1), $this->anna->id, null, 7, DuelStatus::Declined, $this->now()->modify('-1 day'), $this->now()->modify('+2 days'), $this->now());
        $withoutChallenger = new Duel(TestData::uuid(2), null, $this->anna->id, 7, DuelStatus::Declined, $this->now()->modify('-1 day'), $this->now()->modify('+2 days'), $this->now());
        $this->duels->save($withoutOpponent);
        $this->duels->save($withoutChallenger);

        foreach ([$withoutOpponent, $withoutChallenger] as $duel) {
            // A stranger is not "the one whose id is missing".
            foreach ([
                fn () => $this->duelInvitations()->accept($carol->id, $duel->id),
                fn () => $this->duelInvitations()->decline($carol->id, $duel->id),
                fn () => $this->duelInvitations()->cancel($carol->id, $duel->id),
                fn () => $this->showDuels()->show($carol->id, $duel->id),
            ] as $action) {
                try {
                    $action();
                    self::fail('Should have been refused.');
                } catch (DuelNotFound) {
                    $this->addToAssertionCount(1);
                }
            }
            // The one who is left sees it, and cannot answer it any more.
            self::assertSame('declined', $this->showDuels()->show($this->anna->id, $duel->id)->duel->status->value);
            $this->assertNotOpen(fn () => $this->duelInvitations()->accept($this->anna->id, $duel->id));
        }
        self::assertSame([], $this->showDuels()->list($carol->id));
        self::assertEquals([$withoutOpponent, $withoutChallenger], $this->duels->all());
    }

    public function test_both_accounts_are_locked_in_the_order_of_their_ids_whoever_acts(): void
    {
        $inOrder = [$this->anna->id->value, $this->bob->id->value];

        $proposal = $this->duelInvitations()->propose($this->bob->id, $this->anna->id, 7);
        self::assertSame($inOrder, $this->users->lockLog);

        $this->users->lockLog = [];
        $this->duelInvitations()->accept($this->anna->id, $proposal->id);
        self::assertSame($inOrder, $this->users->lockLog);

        foreach (['decline', 'cancel'] as $index => $action) {
            $carol = $this->givenUser("carol-{$action}@example.org");
            $this->givenFriends($this->anna, $carol);
            $this->givenOnboarded($carol, '2026-11-01');
            $this->users->lockLog = [];
            if ($action === 'decline') {
                $this->duelInvitations()->decline($carol->id, $this->givenProposal(20 + $index, $this->anna, $carol)->id);
            } else {
                $this->duelInvitations()->cancel($carol->id, $this->givenProposal(20 + $index, $carol, $this->anna)->id);
            }
            self::assertSame([$this->anna->id->value, $carol->id->value], $this->users->lockLog, $action);
        }
    }

    public function test_FR_COMP_01_an_answer_that_crosses_another_one_sees_the_duel_as_it_is_after_the_wait(): void
    {
        $proposal = $this->givenProposal(1, $this->anna, $this->bob);
        // While Bob's acceptance waits for the accounts, Anna takes the proposal back.
        $this->users->beforeLock = fn () => $this->duels->delete($proposal->id);

        try {
            $this->duelInvitations()->accept($this->bob->id, $proposal->id);
            self::fail('Should have been refused.');
        } catch (DuelNotFound) {
            self::assertSame([], $this->duels->all());
        }
    }

    public function test_FR_ACC_08_an_account_that_was_deleted_in_the_meantime_proposes_and_answers_nothing(): void
    {
        $proposal = $this->givenProposal(1, $this->anna, $this->bob);
        $this->users->delete($this->bob->id);

        foreach ([
            fn () => $this->duelInvitations()->propose($this->bob->id, $this->anna->id, 7),
            fn () => $this->duelInvitations()->accept($this->bob->id, $proposal->id),
            fn () => $this->duelInvitations()->decline($this->bob->id, $proposal->id),
            fn () => $this->duelInvitations()->cancel($this->bob->id, $proposal->id),
        ] as $action) {
            try {
                $action();
                self::fail('Should have been refused.');
            } catch (AccountNotFound) {
                self::assertEquals([$proposal], $this->duels->all());
            }
        }
        // And nobody challenges a friend whose account is gone.
        $this->duels->delete($proposal->id);
        $this->expectException(FriendNotFound::class);
        $this->duelInvitations()->propose($this->anna->id, $this->bob->id, 7);
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertNotOpen(callable $action): void
    {
        try {
            $action();
            self::fail('Should have been refused: the duel is not open for this.');
        } catch (DuelNotOpen) {
            $this->addToAssertionCount(1);
        }
    }
}
