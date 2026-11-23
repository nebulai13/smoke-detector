<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Competition;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Friends\FriendNotFound;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\DuelsUseCaseTestCase;

/**
 * What happens to the duels of two users who stop being friends (HLD §4.8, decided by the owner):
 * a proposal is deleted, and a running duel ends without a winner.
 */
final class EndDuelsTest extends DuelsUseCaseTestCase
{
    private User $anna;
    private User $bob;
    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->anna, $this->bob] = $this->givenTwoFriends();
        $this->carol = $this->givenNamed('Carol');
        $this->givenFriends($this->anna, $this->carol);
        $this->givenOnboarded($this->carol, '2026-11-01', '2026-11-15');
    }

    public function test_FR_FRND_02_removing_a_friend_deletes_the_proposal_between_the_two(): void
    {
        $this->givenProposal(1, $this->bob, $this->anna);
        $other = $this->givenProposal(2, $this->anna, $this->carol);

        $this->friendList()->remove($this->anna->id, $this->bob->id);

        self::assertEquals([$other], $this->duels->all());
    }

    public function test_FR_FRND_02_removing_a_friend_ends_the_running_duel_without_a_winner(): void
    {
        $running = $this->givenRunning(1, $this->anna, $this->bob, '2026-11-03');
        $other = $this->givenRunning(2, $this->carol, $this->anna, '2026-11-03');
        $this->givenDays($this->anna, '2026-11-03', [0, 0, 0, 0, 0, 0, 0]);

        $this->friendList()->remove($this->bob->id, $this->anna->id);

        // Cancelled, and otherwise as it was: no decision, no XP for anybody.
        self::assertEquals(
            [
                new Duel(
                    $running->id, $this->anna->id, $this->bob->id, 7, DuelStatus::Cancelled, $running->createdAt, $running->expiresAt,
                    $running->answeredAt, $running->challengerStart, $running->opponentStart,
                ),
                $other,
            ],
            $this->duels->all(),
        );
        self::assertSame([[], []], [$this->awards($this->anna), $this->awards($this->bob)]);
        // The cron step has nothing to decide between the two any more.
        $this->decideDuels()->execute();
        self::assertSame(DuelStatus::Cancelled, $this->duels->find($running->id)?->status);
        self::assertSame([], $this->awards($this->bob));
    }

    public function test_FR_FRND_02_duels_that_are_over_stay_as_they_are(): void
    {
        $over = [];
        foreach ([DuelStatus::Declined, DuelStatus::Expired, DuelStatus::Cancelled, DuelStatus::Completed, DuelStatus::Forfeited] as $index => $status) {
            $over[] = $this->givenEnded(10 + $index, $this->anna, $this->bob, $status, $status === DuelStatus::Completed ? TestData::decision() : null);
        }

        $this->friendList()->remove($this->anna->id, $this->bob->id);

        self::assertEquals($over, $this->duels->all());
    }

    public function test_FR_FRND_02_a_block_ends_the_duels_of_the_two_in_the_same_way(): void
    {
        $proposal = $this->givenProposal(1, $this->anna, $this->bob);
        $running = $this->givenRunning(2, $this->carol, $this->anna, '2026-11-13');
        $untouched = $this->givenRunning(3, $this->bob, $this->carol, '2026-11-13');

        $this->blocking()->block($this->anna->id, $this->bob->id);
        $this->blocking()->block($this->anna->id, $this->carol->id);

        self::assertNull($this->duels->find($proposal->id));
        self::assertSame(DuelStatus::Cancelled, $this->duels->find($running->id)?->status);
        self::assertEquals($untouched, $this->duels->find($untouched->id));
    }

    public function test_FR_FRND_02_a_removal_that_is_refused_ends_no_duel(): void
    {
        $dave = $this->givenNamed('Dave');
        // Left over from before, between two users who are not friends.
        $running = $this->givenRunning(1, $this->anna, $dave);

        foreach ([fn () => $this->friendList()->remove($this->anna->id, $dave->id), fn () => $this->blocking()->block($this->anna->id, $dave->id)] as $refused) {
            try {
                $refused();
                self::fail('Should have been refused.');
            } catch (FriendNotFound) {
                self::assertEquals([$running], $this->duels->all());
            }
        }
    }

    public function test_FR_FRND_02_a_proposal_whose_72_hours_are_over_goes_too_and_only_the_open_duels_of_the_pair_are_looked_at(): void
    {
        $this->givenProposal(1, $this->anna, $this->bob, 7, '2026-11-01 08:00:00');
        $this->givenRunning(2, $this->bob, $this->anna, '2026-11-13');

        // Whichever of the two is named first.
        $this->endDuels()->between($this->bob->id, $this->anna->id);

        self::assertSame([DuelStatus::Cancelled], array_map(static fn (Duel $duel): DuelStatus => $duel->status, $this->duels->all()));
    }
}
