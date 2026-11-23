<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\Blocks;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\InviteCodes;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every implementation of the friends repositories does (HLD §4.7): the PDO ones against the
 * database and the in-memory ones the use case tests run on.
 */
trait FriendRepositoriesContract
{
    abstract protected function inviteCodes(): InviteCodes;

    abstract protected function friendLinks(): FriendLinks;

    abstract protected function blocks(): Blocks;

    /** Makes sure the accounts exist, where rows cannot exist without one. */
    abstract protected function givenUsers(UserId ...$ids): void;

    public function test_FR_FRND_01_an_invite_code_is_found_by_its_user_and_leads_to_its_owner(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));

        self::assertTrue($this->inviteCodes()->assign(TestData::userId(1), InviteCode::fromInput('K7M2QX9RTB'), TestData::time('2026-11-17 12:00:00')));
        self::assertTrue($this->inviteCodes()->assign(TestData::userId(2), InviteCode::fromInput('K7M2QX9RTC'), TestData::time('2026-11-17 12:00:01')));

        self::assertEquals(InviteCode::fromInput('K7M2QX9RTB'), $this->inviteCodes()->ofUser(TestData::userId(1)));
        self::assertEquals(InviteCode::fromInput('K7M2QX9RTC'), $this->inviteCodes()->ofUser(TestData::userId(2)));
        self::assertEquals(TestData::userId(1), $this->inviteCodes()->owner(InviteCode::fromInput('K7M2QX9RTB')));
        self::assertEquals(TestData::userId(2), $this->inviteCodes()->owner(InviteCode::fromInput('K7M2QX9RTC')));
        self::assertNull($this->inviteCodes()->ofUser(TestData::userId(3)));
        self::assertNull($this->inviteCodes()->owner(InviteCode::fromInput('K7M2QX9RTD')));
    }

    public function test_FR_FRND_01_a_new_code_replaces_the_old_one_which_leads_nowhere_any_more(): void
    {
        $this->givenUsers(TestData::userId(1));
        $this->inviteCodes()->assign(TestData::userId(1), InviteCode::fromInput('K7M2QX9RTB'), TestData::time('2026-11-17 12:00:00'));

        self::assertTrue($this->inviteCodes()->assign(TestData::userId(1), InviteCode::fromInput('ZZZZZZZZZZ'), TestData::time('2026-11-18 12:00:00')));

        self::assertEquals(InviteCode::fromInput('ZZZZZZZZZZ'), $this->inviteCodes()->ofUser(TestData::userId(1)));
        self::assertNull($this->inviteCodes()->owner(InviteCode::fromInput('K7M2QX9RTB')));
        self::assertEquals(TestData::userId(1), $this->inviteCodes()->owner(InviteCode::fromInput('ZZZZZZZZZZ')));
    }

    public function test_FR_FRND_01_the_code_of_another_user_cannot_be_taken(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->inviteCodes()->assign(TestData::userId(1), InviteCode::fromInput('K7M2QX9RTB'), TestData::time('2026-11-17 12:00:00'));
        $this->inviteCodes()->assign(TestData::userId(2), InviteCode::fromInput('2222222222'), TestData::time('2026-11-17 12:00:00'));

        self::assertFalse($this->inviteCodes()->assign(TestData::userId(2), InviteCode::fromInput('K7M2QX9RTB'), TestData::time('2026-11-18 12:00:00')));

        // Nothing changed for either of them.
        self::assertEquals(TestData::userId(1), $this->inviteCodes()->owner(InviteCode::fromInput('K7M2QX9RTB')));
        self::assertEquals(InviteCode::fromInput('2222222222'), $this->inviteCodes()->ofUser(TestData::userId(2)));
        // The own code again is no conflict.
        self::assertTrue($this->inviteCodes()->assign(TestData::userId(1), InviteCode::fromInput('K7M2QX9RTB'), TestData::time('2026-11-18 12:00:00')));
        self::assertEquals(InviteCode::fromInput('K7M2QX9RTB'), $this->inviteCodes()->ofUser(TestData::userId(1)));
    }

    public function test_FR_FRND_01_a_request_is_found_from_both_sides_with_every_value(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        // Once asked by the user with the larger id, once by the one with the smaller id.
        $fromTwo = TestData::friendship(2, 1, '2026-11-15 10:00:00');
        $fromOne = TestData::friendship(1, 3, '2026-11-15 11:30:59', '2026-11-16 08:00:01');

        $this->friendLinks()->save($fromTwo);
        $this->friendLinks()->save($fromOne);

        self::assertEquals($fromTwo, $this->friendLinks()->between(TestData::userId(1), TestData::userId(2)));
        self::assertEquals($fromTwo, $this->friendLinks()->between(TestData::userId(2), TestData::userId(1)));
        self::assertEquals($fromOne, $this->friendLinks()->between(TestData::userId(3), TestData::userId(1)));
        self::assertNull($this->friendLinks()->between(TestData::userId(2), TestData::userId(3)));
    }

    public function test_FR_FRND_01_two_users_have_one_link_whoever_saves_it(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->friendLinks()->save(TestData::friendship(2, 1, '2026-11-15 10:00:00'));

        // Accepted: the same request, saved again. And then a new request in the other direction.
        $this->friendLinks()->save(TestData::friendship(2, 1, '2026-11-15 10:00:00', '2026-11-16 08:00:00'));
        self::assertEquals(
            [TestData::friendship(2, 1, '2026-11-15 10:00:00', '2026-11-16 08:00:00')],
            $this->friendLinks()->ofUser(TestData::userId(1)),
        );

        $this->friendLinks()->save(TestData::friendship(1, 2, '2026-11-17 09:00:00'));
        self::assertEquals([TestData::friendship(1, 2, '2026-11-17 09:00:00')], $this->friendLinks()->ofUser(TestData::userId(1)));
        self::assertEquals([TestData::friendship(1, 2, '2026-11-17 09:00:00')], $this->friendLinks()->ofUser(TestData::userId(2)));
    }

    public function test_FR_FRND_02_a_link_is_deleted_from_either_side_and_deleting_nothing_does_nothing(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $this->friendLinks()->save(TestData::friendship(1, 2));
        $this->friendLinks()->save(TestData::friendship(3, 1));
        $this->friendLinks()->save(TestData::friendship(2, 3));

        $this->friendLinks()->delete(TestData::userId(2), TestData::userId(1));
        $this->friendLinks()->delete(TestData::userId(1), TestData::userId(3));
        $this->friendLinks()->delete(TestData::userId(1), TestData::userId(3));

        self::assertSame([], $this->friendLinks()->ofUser(TestData::userId(1)));
        self::assertEquals([TestData::friendship(2, 3)], $this->friendLinks()->ofUser(TestData::userId(2)));
    }

    public function test_FR_FRND_02_the_links_of_a_user_are_listed_oldest_request_first_and_nobody_elses(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3), TestData::userId(4), TestData::userId(5));
        $this->friendLinks()->save(TestData::friendship(1, 4, '2026-11-15 10:00:00'));
        $this->friendLinks()->save(TestData::friendship(3, 1, '2026-11-14 10:00:00', '2026-11-16 10:00:00'));
        // Asked in the same second as user 4 was: the smaller id of the other user comes first.
        $this->friendLinks()->save(TestData::friendship(2, 1, '2026-11-15 10:00:00'));
        $this->friendLinks()->save(TestData::friendship(4, 5, '2026-11-01 10:00:00'));

        self::assertEquals(
            [
                TestData::friendship(3, 1, '2026-11-14 10:00:00', '2026-11-16 10:00:00'),
                TestData::friendship(2, 1, '2026-11-15 10:00:00'),
                TestData::friendship(1, 4, '2026-11-15 10:00:00'),
            ],
            $this->friendLinks()->ofUser(TestData::userId(1)),
        );
        self::assertSame([], $this->friendLinks()->ofUser(TestData::userId(6)));
    }

    public function test_FR_FRND_02_a_block_has_a_direction_and_is_seen_from_both_sides(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));

        $this->blocks()->add(TestData::block(1, 2));

        self::assertTrue($this->blocks()->isBlocked(TestData::userId(1), TestData::userId(2)));
        self::assertFalse($this->blocks()->isBlocked(TestData::userId(2), TestData::userId(1)));
        self::assertTrue($this->blocks()->between(TestData::userId(1), TestData::userId(2)));
        self::assertTrue($this->blocks()->between(TestData::userId(2), TestData::userId(1)));
        self::assertFalse($this->blocks()->between(TestData::userId(1), TestData::userId(3)));
        self::assertFalse($this->blocks()->between(TestData::userId(3), TestData::userId(2)));
    }

    public function test_FR_FRND_02_blocking_again_keeps_the_first_block_and_each_can_block_the_other(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->blocks()->add(TestData::block(1, 2, '2026-11-16 09:00:00'));

        $this->blocks()->add(TestData::block(1, 2, '2026-11-17 09:00:00'));
        $this->blocks()->add(TestData::block(2, 1, '2026-11-17 10:00:00'));

        self::assertEquals([TestData::block(1, 2, '2026-11-16 09:00:00')], $this->blocks()->byUser(TestData::userId(1)));
        self::assertEquals([TestData::block(2, 1, '2026-11-17 10:00:00')], $this->blocks()->byUser(TestData::userId(2)));
    }

    public function test_FR_FRND_02_a_block_is_lifted_in_its_direction_only(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->blocks()->add(TestData::block(1, 2));
        $this->blocks()->add(TestData::block(2, 1));

        self::assertTrue($this->blocks()->remove(TestData::userId(1), TestData::userId(2)));
        // There is nothing to lift a second time.
        self::assertFalse($this->blocks()->remove(TestData::userId(1), TestData::userId(2)));

        self::assertFalse($this->blocks()->isBlocked(TestData::userId(1), TestData::userId(2)));
        self::assertTrue($this->blocks()->isBlocked(TestData::userId(2), TestData::userId(1)));
        self::assertTrue($this->blocks()->between(TestData::userId(1), TestData::userId(2)));
    }

    public function test_FR_FRND_02_the_blocks_of_a_user_are_listed_oldest_first_and_not_who_blocked_them(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3), TestData::userId(4));
        $this->blocks()->add(TestData::block(1, 4, '2026-11-16 09:00:00'));
        $this->blocks()->add(TestData::block(1, 3, '2026-11-15 09:00:00'));
        // In the same second as user 4: the smaller id comes first.
        $this->blocks()->add(TestData::block(1, 2, '2026-11-16 09:00:00'));
        $this->blocks()->add(TestData::block(3, 1, '2026-11-01 09:00:00'));

        self::assertEquals(
            [TestData::block(1, 3, '2026-11-15 09:00:00'), TestData::block(1, 2, '2026-11-16 09:00:00'), TestData::block(1, 4, '2026-11-16 09:00:00')],
            $this->blocks()->byUser(TestData::userId(1)),
        );
        self::assertSame([], $this->blocks()->byUser(TestData::userId(2)));
    }
}
