<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Friends;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Badge\AwardedBadge;
use SmokeDetector\Application\Friends\AlreadyFriends;
use SmokeDetector\Application\Friends\FriendLimitReached;
use SmokeDetector\Application\Friends\FriendRequestNotFound;
use SmokeDetector\Application\Friends\FriendRequests;
use SmokeDetector\Application\Friends\Friendship;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\InviteNotFound;
use SmokeDetector\Application\Friends\OwnInvite;
use SmokeDetector\Application\Recovery\ReachedMilestone;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\FriendsUseCaseTestCase;

/**
 * Friend requests (SRS FR-FRND-01, FR-FRND-04; HLD §4.7): a request is sent with the invite code of
 * the other user, who accepts or declines. Nobody has more than 50 friends.
 *
 * Anna is the first account and Bob the second, so Anna has the smaller id.
 */
final class FriendRequestsTest extends FriendsUseCaseTestCase
{
    private User $anna;
    private User $bob;
    private InviteCode $bobsCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenNamed('Anna');
        $this->bob = $this->givenNamed('Bob');
        $this->bobsCode = $this->givenCode($this->bob);
    }

    public function test_FR_FRND_01_a_request_sent_with_the_code_waits_for_the_owner(): void
    {
        $sent = $this->friendRequests()->send($this->anna->id, $this->bobsCode);

        self::assertFalse($sent->accepted);
        // Whoever has the code sees the name and the avatar of its owner, to know whom they asked.
        self::assertSame([$this->bob->id->value, 'Bob', 'avatar-07'], self::profile($sent->user));
        self::assertEquals([new Friendship($this->anna->id, $this->bob->id, $this->now())], $this->friendLinks->all());
    }

    public function test_FR_FRND_01_the_same_request_sent_again_changes_nothing(): void
    {
        $this->friendRequests()->send($this->anna->id, $this->bobsCode);
        $first = $this->now();
        $this->clock->advanceSeconds(600);

        $sent = $this->friendRequests()->send($this->anna->id, $this->bobsCode);

        self::assertFalse($sent->accepted);
        self::assertSame('Bob', $sent->user->displayName->value);
        self::assertEquals([new Friendship($this->anna->id, $this->bob->id, $first)], $this->friendLinks->all());
    }

    public function test_FR_FRND_01_when_the_owner_had_asked_first_both_have_agreed_and_are_friends(): void
    {
        $request = $this->givenRequest($this->bob, $this->anna, '2026-11-15 10:00:00');

        $sent = $this->friendRequests()->send($this->anna->id, $this->bobsCode);

        self::assertTrue($sent->accepted);
        self::assertSame('Bob', $sent->user->displayName->value);
        // Bob's request, accepted now: who asked and when stays as it was.
        self::assertEquals([new Friendship($this->bob->id, $this->anna->id, $request->requestedAt, $this->now())], $this->friendLinks->all());
    }

    public function test_FR_FRND_01_a_code_nobody_has_leads_nowhere(): void
    {
        $this->expectException(InviteNotFound::class);

        $this->friendRequests()->send($this->anna->id, InviteCode::fromInput('ZZZZZZZZZZ'));
    }

    public function test_FR_FRND_01_nobody_asks_themselves(): void
    {
        try {
            $this->friendRequests()->send($this->bob->id, $this->bobsCode);
            self::fail('Should have been refused.');
        } catch (OwnInvite) {
            self::assertSame([], $this->friendLinks->all());
        }
    }

    public function test_FR_FRND_01_friends_cannot_ask_each_other_again(): void
    {
        $friendship = $this->givenFriends($this->bob, $this->anna);
        $annasCode = $this->givenCode($this->anna, 'ZZZZZZZZZZ');

        foreach ([[$this->anna, $this->bobsCode], [$this->bob, $annasCode]] as [$user, $code]) {
            try {
                $this->friendRequests()->send($user->id, $code);
                self::fail('Should have been refused.');
            } catch (AlreadyFriends) {
                self::assertEquals([$friendship], $this->friendLinks->all());
            }
        }
    }

    public function test_FR_FRND_02_between_two_users_with_a_block_a_code_seems_not_to_exist(): void
    {
        $annasCode = $this->givenCode($this->anna, 'ZZZZZZZZZZ');
        $this->givenBlock($this->bob, $this->anna);

        // Neither the one who is blocked nor the one who blocks can ask.
        foreach ([[$this->anna, $this->bobsCode], [$this->bob, $annasCode]] as [$user, $code]) {
            try {
                $this->friendRequests()->send($user->id, $code);
                self::fail('Should have been refused.');
            } catch (InviteNotFound) {
                self::assertSame([], $this->friendLinks->all());
            }
        }
    }

    public function test_FR_FRND_04_the_fiftieth_friend_or_open_request_is_the_last_one_a_user_can_add(): void
    {
        self::assertSame(50, FriendRequests::MAX_FRIENDS);
        // 30 friends and 19 requests of her own: 49. What others have asked her does not count.
        $this->givenFriendsMore($this->anna, 30);
        $this->givenOutgoingMore($this->anna, 19);
        $this->givenIncomingMore($this->anna, 5);

        self::assertFalse($this->friendRequests()->send($this->anna->id, $this->bobsCode)->accepted);

        $carol = $this->givenNamed('Carol');
        $carolsCode = $this->givenCode($carol, 'ZZZZZZZZZZ');
        $links = $this->friendLinks->all();
        try {
            $this->friendRequests()->send($this->anna->id, $carolsCode);
            self::fail('Should have been refused.');
        } catch (FriendLimitReached) {
            self::assertEquals($links, $this->friendLinks->all());
        }
        // The request that is there already can be sent again all the same.
        self::assertFalse($this->friendRequests()->send($this->anna->id, $this->bobsCode)->accepted);
    }

    public function test_FR_FRND_04_how_many_friends_the_owner_of_the_code_has_does_not_stop_a_request(): void
    {
        // Otherwise the answer would tell a stranger how many friends somebody has.
        $this->givenFriendsMore($this->bob, 50);

        self::assertFalse($this->friendRequests()->send($this->anna->id, $this->bobsCode)->accepted);
    }

    public function test_FR_FRND_04_agreeing_by_code_needs_room_like_every_acceptance(): void
    {
        $this->givenRequest($this->bob, $this->anna);
        // 44 friends and 5 requests of her own: 49, so there is room for Bob.
        $this->givenFriendsMore($this->anna, 44);
        $this->givenOutgoingMore($this->anna, 5);

        self::assertTrue($this->friendRequests()->send($this->anna->id, $this->bobsCode)->accepted);
    }

    public function test_FR_FRND_04_agreeing_by_code_is_refused_when_the_one_who_agrees_is_full_and_only_then(): void
    {
        $carol = $this->givenNamed('Carol');
        $carolsCode = $this->givenCode($carol, 'ZZZZZZZZZZ');
        $this->givenRequest($this->bob, $this->anna);
        $this->givenRequest($carol, $this->anna);
        // Bob is as full as one can be: 49 friends and his request to Anna. That does not stop her.
        $this->givenFriendsMore($this->bob, 49);
        $this->givenFriendsMore($this->anna, 44);
        $this->givenOutgoingMore($this->anna, 5);

        self::assertTrue($this->friendRequests()->send($this->anna->id, $this->bobsCode)->accepted);

        // Now Anna has 45 friends and 5 requests of her own: full.
        $links = $this->friendLinks->all();
        try {
            $this->friendRequests()->send($this->anna->id, $carolsCode);
            self::fail('Should have been refused.');
        } catch (FriendLimitReached) {
            self::assertEquals($links, $this->friendLinks->all());
        }
    }

    public function test_FR_FRND_01_a_code_that_is_renewed_while_the_request_waits_leads_nowhere(): void
    {
        $this->users->beforeLock = function (): void {
            $this->inviteCodes->assign($this->bob->id, InviteCode::fromInput('ZZZZZZZZZZ'), $this->now());
        };

        try {
            $this->friendRequests()->send($this->anna->id, $this->bobsCode);
            self::fail('Should have been refused.');
        } catch (InviteNotFound) {
            self::assertSame([], $this->friendLinks->all());
        }
    }

    public function test_FR_ACC_08_the_owner_of_the_code_was_deleted_in_the_meantime(): void
    {
        $this->users->beforeLock = fn () => $this->users->delete($this->bob->id);

        try {
            $this->friendRequests()->send($this->anna->id, $this->bobsCode);
            self::fail('Should have been refused.');
        } catch (InviteNotFound) {
            self::assertSame([], $this->friendLinks->all());
        }
    }

    public function test_FR_ACC_08_the_sender_was_deleted_in_the_meantime(): void
    {
        $this->users->beforeLock = fn () => $this->users->delete($this->anna->id);

        try {
            $this->friendRequests()->send($this->anna->id, $this->bobsCode);
            self::fail('Should have been refused.');
        } catch (AccountNotFound) {
            self::assertSame([], $this->friendLinks->all());
        }
    }

    public function test_both_accounts_are_locked_in_the_order_of_their_ids_whoever_acts(): void
    {
        // Two requests that cross each other would otherwise wait for each other for ever.
        $annasCode = $this->givenCode($this->anna, 'ZZZZZZZZZZ');
        $inOrder = [$this->anna->id->value, $this->bob->id->value];

        $this->friendRequests()->send($this->anna->id, $this->bobsCode);
        self::assertSame($inOrder, $this->users->lockLog);

        $this->users->lockLog = [];
        $this->friendRequests()->send($this->bob->id, $annasCode);
        self::assertSame($inOrder, $this->users->lockLog);

        foreach (['accept', 'decline', 'withdraw'] as $action) {
            $carol = $this->givenUser("carol-{$action}@example.org");
            $inOrder = [$this->anna->id->value, $carol->id->value];

            // Carol answers Anna's request, or takes her own back: the larger id acts.
            $action === 'withdraw' ? $this->givenRequest($carol, $this->anna) : $this->givenRequest($this->anna, $carol);
            $this->users->lockLog = [];
            match ($action) {
                'accept' => $this->friendRequests()->accept($carol->id, $this->anna->id),
                'decline' => $this->friendRequests()->decline($carol->id, $this->anna->id),
                'withdraw' => $this->friendRequests()->withdraw($carol->id, $this->anna->id),
            };
            self::assertSame($inOrder, $this->users->lockLog, $action);
        }
    }

    public function test_FR_FRND_01_an_accepted_request_makes_the_two_friends(): void
    {
        $request = $this->givenRequest($this->bob, $this->anna, '2026-11-15 10:00:00');
        $this->givenOnboarded($this->bob, streaks: [3, 12, 2, 8, 1], totalXp: 640, level: 3);

        $friend = $this->friendRequests()->accept($this->anna->id, $this->bob->id);

        self::assertEquals([new Friendship($this->bob->id, $this->anna->id, $request->requestedAt, $this->now())], $this->friendLinks->all());
        // The new friend with the aggregates friends see: level, XP and the streaks. Not the freezes.
        self::assertSame(['Bob', '2026-11-17 12:00:00', 3, 640, [3, 12, 2, 8]], self::friend($friend));
        self::assertSame([$this->bob->id->value, 'Bob', 'avatar-07'], self::profile($friend->user));
    }

    public function test_FR_FRND_03_a_new_friend_shows_the_badges_held_and_not_when_they_were_earned(): void
    {
        $this->givenRequest($this->bob, $this->anna);
        // Stored in another order than the badge cases have. Anna's own badge is not Bob's.
        $this->badgeRecords->add(new AwardedBadge($this->bob->id, Badge::CheckInStreak7, TestData::time('2026-11-16 23:00:00')));
        $this->badgeRecords->add(new AwardedBadge($this->bob->id, Badge::FirstCheckIn, TestData::time('2026-11-09 23:00:00')));
        $this->badgeRecords->add(new AwardedBadge($this->anna->id, Badge::Cheerleader, TestData::time('2026-11-09 23:00:00')));

        $friend = $this->friendRequests()->accept($this->anna->id, $this->bob->id);

        // The same view as in the list of friends (FriendListTest).
        self::assertSame([Badge::FirstCheckIn, Badge::CheckInStreak7], $friend->badges);
    }

    public function test_FR_FRND_03_a_new_friend_shows_the_health_milestones_the_new_friends_setting_allows(): void
    {
        $this->givenRequest($this->bob, $this->anna);
        foreach ([HealthMilestone::Circulation, HealthMilestone::HeartRate] as $milestone) {
            $this->milestoneRecords->add(new ReachedMilestone($this->bob->id, $milestone, TestData::time('2026-11-15 08:00:00')));
        }

        // Bob never chose: the milestones from two weeks on. The same view as in the list of friends.
        self::assertSame([HealthMilestone::Circulation], $this->friendRequests()->accept($this->anna->id, $this->bob->id)->healthMilestones);
    }

    public function test_FR_FRND_03_a_new_friend_who_keeps_the_milestones_private_shows_none(): void
    {
        $this->givenRequest($this->bob, $this->anna);
        $this->milestoneRecords->add(new ReachedMilestone($this->bob->id, HealthMilestone::Circulation, TestData::time('2026-11-15 08:00:00')));
        $this->userSettings->save($this->bob->id, new Settings(MilestoneSharing::Private));

        self::assertSame([], $this->friendRequests()->accept($this->anna->id, $this->bob->id)->healthMilestones);
    }

    public function test_FR_FRND_03_a_new_friend_who_has_not_done_the_onboarding_has_nothing_to_show(): void
    {
        $this->givenRequest($this->bob, $this->anna);

        self::assertSame(['Bob', '2026-11-17 12:00:00', 0, 0, [0, 0, 0, 0]], self::friend($this->friendRequests()->accept($this->anna->id, $this->bob->id)));
    }

    public function test_FR_FRND_01_only_the_one_who_was_asked_can_accept_and_only_an_open_request(): void
    {
        // No request at all.
        $this->assertNoRequest(fn () => $this->friendRequests()->accept($this->anna->id, $this->bob->id));

        // Anna's own request: she cannot accept it for Bob.
        $request = $this->givenRequest($this->anna, $this->bob);
        $this->assertNoRequest(fn () => $this->friendRequests()->accept($this->anna->id, $this->bob->id));
        self::assertEquals([$request], $this->friendLinks->all());

        // Accepted already: the time of the first acceptance stays.
        $friendship = $this->givenFriends($this->bob, $this->anna);
        $this->assertNoRequest(fn () => $this->friendRequests()->accept($this->anna->id, $this->bob->id));
        self::assertEquals([$friendship], $this->friendLinks->all());
    }

    public function test_FR_FRND_04_a_request_is_accepted_against_the_room_of_the_one_who_accepts(): void
    {
        $carol = $this->givenNamed('Carol');
        $this->givenRequest($this->bob, $this->anna);
        $this->givenRequest($carol, $this->anna);
        // 30 friends and 19 requests of her own: 49. Requests from others do not count.
        $this->givenFriendsMore($this->anna, 30);
        $this->givenOutgoingMore($this->anna, 19);
        $this->givenIncomingMore($this->anna, 3);

        $this->friendRequests()->accept($this->anna->id, $this->bob->id);

        // 31 friends and 19 requests: full. Her own open requests keep their places.
        $links = $this->friendLinks->all();
        try {
            $this->friendRequests()->accept($this->anna->id, $carol->id);
            self::fail('Should have been refused.');
        } catch (FriendLimitReached) {
            self::assertEquals($links, $this->friendLinks->all());
        }

        // One request taken back, and there is room for Carol.
        $asked = $this->friendList()->show($this->anna->id)->outgoing[0]->user->userId;
        $this->friendRequests()->withdraw($this->anna->id, $asked);
        self::assertSame('Carol', $this->friendRequests()->accept($this->anna->id, $carol->id)->user->displayName->value);
    }

    public function test_FR_FRND_03_whether_a_request_can_be_accepted_never_depends_on_the_sender(): void
    {
        // Otherwise the refusal would tell the one who was asked how many friends the sender has.
        // A sender's request has its place among the sender's 50 from the moment it is sent, so
        // accepting it changes nothing for the sender. Here the sender even has 50 friends on top,
        // which the rules do not let happen: the answer is the same all the same.
        $this->givenRequest($this->bob, $this->anna);
        $this->givenFriendsMore($this->bob, 50);
        $this->givenOutgoingMore($this->bob, 5);

        $friend = $this->friendRequests()->accept($this->anna->id, $this->bob->id);

        self::assertSame('Bob', $friend->user->displayName->value);
    }

    public function test_FR_ACC_08_a_request_of_an_account_that_was_deleted_in_the_meantime_cannot_be_accepted(): void
    {
        $this->givenRequest($this->bob, $this->anna);
        $this->users->beforeLock = fn () => $this->users->delete($this->bob->id);

        $this->assertNoRequest(fn () => $this->friendRequests()->accept($this->anna->id, $this->bob->id));

        $this->users->delete($this->anna->id);
        $this->expectException(AccountNotFound::class);
        $this->friendRequests()->accept($this->anna->id, $this->bob->id);
    }

    public function test_FR_FRND_01_a_declined_request_is_gone_and_the_other_can_ask_again(): void
    {
        $this->givenRequest($this->bob, $this->anna);
        $carol = $this->givenNamed('Carol');
        $other = $this->givenRequest($carol, $this->anna);

        $this->friendRequests()->decline($this->anna->id, $this->bob->id);

        self::assertEquals([$other], $this->friendLinks->all());
        $annasCode = $this->givenCode($this->anna, 'ZZZZZZZZZZ');
        self::assertFalse($this->friendRequests()->send($this->bob->id, $annasCode)->accepted);
    }

    public function test_FR_FRND_01_only_an_open_request_to_the_user_can_be_declined(): void
    {
        $this->assertNoRequest(fn () => $this->friendRequests()->decline($this->anna->id, $this->bob->id));

        $request = $this->givenRequest($this->anna, $this->bob);
        $this->assertNoRequest(fn () => $this->friendRequests()->decline($this->anna->id, $this->bob->id));
        self::assertEquals([$request], $this->friendLinks->all());

        // A friendship is not ended by declining.
        $friendship = $this->givenFriends($this->bob, $this->anna);
        $this->assertNoRequest(fn () => $this->friendRequests()->decline($this->anna->id, $this->bob->id));
        self::assertEquals([$friendship], $this->friendLinks->all());
    }

    public function test_FR_FRND_01_the_sender_can_take_an_open_request_back(): void
    {
        $this->givenRequest($this->anna, $this->bob);
        $carol = $this->givenNamed('Carol');
        $other = $this->givenRequest($this->anna, $carol);

        $this->friendRequests()->withdraw($this->anna->id, $this->bob->id);

        self::assertEquals([$other], $this->friendLinks->all());
    }

    public function test_FR_FRND_01_only_an_open_request_of_the_user_can_be_taken_back(): void
    {
        $this->assertNoRequest(fn () => $this->friendRequests()->withdraw($this->anna->id, $this->bob->id));

        // Bob's request to Anna is not hers to take back.
        $request = $this->givenRequest($this->bob, $this->anna);
        $this->assertNoRequest(fn () => $this->friendRequests()->withdraw($this->anna->id, $this->bob->id));
        self::assertEquals([$request], $this->friendLinks->all());

        $friendship = $this->givenFriends($this->anna, $this->bob);
        $this->assertNoRequest(fn () => $this->friendRequests()->withdraw($this->anna->id, $this->bob->id));
        self::assertEquals([$friendship], $this->friendLinks->all());
    }

    public function test_FR_ACC_08_declining_and_taking_back_need_the_account_of_the_user(): void
    {
        $this->givenRequest($this->bob, $this->anna);
        $this->users->delete($this->anna->id);

        foreach ([
            fn () => $this->friendRequests()->decline($this->anna->id, $this->bob->id),
            fn () => $this->friendRequests()->withdraw($this->anna->id, $this->bob->id),
        ] as $action) {
            try {
                $action();
                self::fail('Should have been refused.');
            } catch (AccountNotFound) {
                self::assertCount(1, $this->friendLinks->all());
            }
        }
    }

    public function test_FR_FRND_01_nobody_has_a_request_with_themselves(): void
    {
        $this->assertNoRequest(fn () => $this->friendRequests()->accept($this->anna->id, $this->anna->id));
        $this->assertNoRequest(fn () => $this->friendRequests()->decline($this->anna->id, $this->anna->id));
        $this->assertNoRequest(fn () => $this->friendRequests()->withdraw($this->anna->id, $this->anna->id));
        self::assertSame([], $this->friendLinks->all());
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertNoRequest(callable $action): void
    {
        try {
            $action();
            self::fail('Should have been refused: there is no such request.');
        } catch (FriendRequestNotFound) {
            $this->addToAssertionCount(1);
        }
    }
}
