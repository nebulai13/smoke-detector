<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Friends;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Friends\Block;
use SmokeDetector\Application\Friends\BlockedUser;
use SmokeDetector\Application\Friends\BlockNotFound;
use SmokeDetector\Application\Friends\FriendNotFound;
use SmokeDetector\Tests\Unit\Application\FriendsUseCaseTestCase;

/**
 * Blocking (SRS FR-FRND-02, HLD §4.7): it ends the friendship or the request and hides the two
 * from each other. A user can block whom they can see: a friend, or somebody with an open request.
 */
final class BlockingTest extends FriendsUseCaseTestCase
{
    private User $anna;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenNamed('Anna');
        $this->bob = $this->givenNamed('Bob');
    }

    public function test_FR_FRND_02_blocking_a_friend_ends_the_friendship_and_stores_the_block(): void
    {
        $this->givenFriends($this->bob, $this->anna);
        $carol = $this->givenNamed('Carol');
        $other = $this->givenFriends($this->anna, $carol);

        $this->blocking()->block($this->anna->id, $this->bob->id);

        self::assertEquals([$other], $this->friendLinks->all());
        self::assertEquals([new Block($this->anna->id, $this->bob->id, $this->now())], $this->blocks->all());
    }

    public function test_FR_FRND_02_somebody_with_an_open_request_can_be_blocked_whoever_asked(): void
    {
        $carol = $this->givenNamed('Carol');
        $this->givenRequest($this->bob, $this->anna);
        $this->givenRequest($this->anna, $carol);

        $this->blocking()->block($this->anna->id, $this->bob->id);
        $this->blocking()->block($this->anna->id, $carol->id);

        self::assertSame([], $this->friendLinks->all());
        self::assertEquals(
            [new Block($this->anna->id, $this->bob->id, $this->now()), new Block($this->anna->id, $carol->id, $this->now())],
            $this->blocks->all(),
        );
    }

    public function test_FR_FRND_02_a_stranger_cannot_be_blocked(): void
    {
        // Otherwise the answer would tell whether a user id exists.
        $carol = $this->givenNamed('Carol');
        $this->givenFriends($this->bob, $carol);

        try {
            $this->blocking()->block($this->anna->id, $this->bob->id);
            self::fail('Should have been refused.');
        } catch (FriendNotFound) {
            self::assertSame([], $this->blocks->all());
            self::assertCount(1, $this->friendLinks->all());
        }
    }

    public function test_FR_FRND_02_blocking_somebody_who_is_blocked_already_changes_nothing(): void
    {
        $block = $this->givenBlock($this->anna, $this->bob, '2026-11-16 09:00:00');

        $this->blocking()->block($this->anna->id, $this->bob->id);

        self::assertEquals([$block], $this->blocks->all());
    }

    public function test_FR_FRND_02_somebody_who_has_blocked_the_user_cannot_be_seen_and_so_not_be_blocked(): void
    {
        $block = $this->givenBlock($this->bob, $this->anna);

        try {
            $this->blocking()->block($this->anna->id, $this->bob->id);
            self::fail('Should have been refused.');
        } catch (FriendNotFound) {
            self::assertEquals([$block], $this->blocks->all());
        }
    }

    public function test_FR_FRND_02_nobody_blocks_themselves(): void
    {
        try {
            $this->blocking()->block($this->anna->id, $this->anna->id);
            self::fail('Should have been refused.');
        } catch (FriendNotFound) {
            self::assertSame([], $this->blocks->all());
        }

        $this->expectException(BlockNotFound::class);
        $this->blocking()->unblock($this->anna->id, $this->anna->id);
    }

    public function test_both_accounts_are_locked_in_the_order_of_their_ids_whoever_blocks(): void
    {
        $this->givenFriends($this->anna, $this->bob);
        $this->blocking()->block($this->bob->id, $this->anna->id);
        self::assertSame([$this->anna->id->value, $this->bob->id->value], $this->users->lockLog);

        $carol = $this->givenNamed('Carol');
        $this->givenFriends($this->anna, $carol);
        $this->users->lockLog = [];
        $this->blocking()->block($this->anna->id, $carol->id);
        self::assertSame([$this->anna->id->value, $carol->id->value], $this->users->lockLog);
    }

    public function test_FR_ACC_08_an_account_that_was_deleted_in_the_meantime_is_not_blocked_and_blocks_nobody(): void
    {
        $this->givenFriends($this->anna, $this->bob);
        $this->users->beforeLock = fn () => $this->users->delete($this->bob->id);

        try {
            $this->blocking()->block($this->anna->id, $this->bob->id);
            self::fail('Should have been refused.');
        } catch (FriendNotFound) {
            self::assertSame([], $this->blocks->all());
        }

        $this->users->delete($this->anna->id);
        foreach ([fn () => $this->blocking()->block($this->anna->id, $this->bob->id), fn () => $this->blocking()->unblock($this->anna->id, $this->bob->id)] as $action) {
            try {
                $action();
                self::fail('Should have been refused.');
            } catch (AccountNotFound) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertCount(1, $this->friendLinks->all());
    }

    public function test_FR_FRND_02_lifting_a_block_removes_it_and_nothing_else(): void
    {
        $carol = $this->givenNamed('Carol');
        $this->givenBlock($this->anna, $this->bob);
        $carols = $this->givenBlock($this->anna, $carol);
        $bobs = $this->givenBlock($this->bob, $this->anna);

        $this->blocking()->unblock($this->anna->id, $this->bob->id);

        // Bob's own block of Anna stays: only he can lift it. The two are not friends again either.
        self::assertEquals([$carols, $bobs], $this->blocks->all());
        self::assertSame([], $this->friendLinks->all());
        self::assertSame([$this->anna->id->value], $this->users->lockLog);
    }

    public function test_FR_FRND_02_only_a_block_of_the_user_can_be_lifted(): void
    {
        $bobs = $this->givenBlock($this->bob, $this->anna);

        try {
            $this->blocking()->unblock($this->anna->id, $this->bob->id);
            self::fail('Should have been refused.');
        } catch (BlockNotFound) {
            self::assertEquals([$bobs], $this->blocks->all());
        }
    }

    public function test_FR_FRND_02_a_user_sees_whom_they_have_blocked_oldest_first_and_not_who_has_blocked_them(): void
    {
        $carol = $this->givenNamed('Carol');
        $dave = $this->givenNamed('Dave');
        $erin = $this->givenNamed('Erin');
        $this->givenBlock($this->anna, $carol, '2026-11-16 09:00:00');
        $this->givenBlock($this->anna, $this->bob, '2026-11-15 09:00:00');
        $this->givenBlock($dave, $this->anna, '2026-11-01 09:00:00');
        // Blocked once, and the account is gone since: nothing to show.
        $this->givenBlock($this->anna, $erin, '2026-11-02 09:00:00');
        $this->users->delete($erin->id);

        $blocked = $this->blocking()->blocked($this->anna->id);

        self::assertSame(
            [['Bob', '2026-11-15 09:00:00'], ['Carol', '2026-11-16 09:00:00']],
            array_map(static fn (BlockedUser $entry): array => [$entry->user->displayName->value, $entry->blockedAt->format('Y-m-d H:i:s')], $blocked),
        );
        self::assertSame([$this->bob->id->value, 'Bob', 'avatar-07'], self::profile($blocked[0]->user));
        self::assertSame([], $this->blocking()->blocked($this->bob->id));
    }
}
