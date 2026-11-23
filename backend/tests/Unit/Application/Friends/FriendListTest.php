<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Friends;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Badge\AwardedBadge;
use SmokeDetector\Application\Friends\FriendNotFound;
use SmokeDetector\Application\Friends\FriendView;
use SmokeDetector\Application\Recovery\ReachedMilestone;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\FriendsUseCaseTestCase;

/**
 * The friends of a user and what is seen of them (SRS FR-FRND-02, FR-FRND-03; HLD §4.7): the name,
 * the avatar and aggregates. Never a count, a limit, the baseline or the time of a log.
 */
final class FriendListTest extends FriendsUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenNamed('Anna');
    }

    public function test_FR_FRND_02_somebody_without_friends_sees_three_empty_lists(): void
    {
        // Other people's friendships are not hers.
        $this->givenFriends($this->givenNamed('Bob'), $this->givenNamed('Carol'));

        $overview = $this->friendList()->show($this->anna->id);

        self::assertSame([[], [], []], [$overview->friends, $overview->incoming, $overview->outgoing]);
    }

    public function test_FR_FRND_03_friends_are_listed_by_name_with_their_aggregates(): void
    {
        $carl = $this->givenNamed('carl');
        $bert = $this->givenNamed('Bert');
        $anton = $this->givenNamed('anton');
        $this->givenFriends($this->anna, $carl, '2026-11-12 08:00:00');
        $this->givenFriends($bert, $this->anna, '2026-11-16 08:00:00');
        $this->givenFriends($anton, $this->anna, '2026-11-14 09:30:00');
        $this->givenOnboarded($carl, streaks: [3, 12, 2, 8, 1], totalXp: 640, level: 3);
        $this->givenOnboarded($anton, streaks: [0, 5, 0, 1, 2], totalXp: 90, level: 0);

        $overview = $this->friendList()->show($this->anna->id);

        // Without regard to capitals: "Bert" is not in front of "anton". Bert has no plan yet.
        self::assertSame(
            [
                ['anton', '2026-11-14 09:30:00', 0, 90, [0, 5, 0, 1]],
                ['Bert', '2026-11-16 08:00:00', 0, 0, [0, 0, 0, 0]],
                ['carl', '2026-11-12 08:00:00', 3, 640, [3, 12, 2, 8]],
            ],
            array_map(self::friend(...), $overview->friends),
        );
        self::assertSame([$carl->id->value, 'carl', 'avatar-07'], self::profile($overview->friends[2]->user));
        self::assertSame([[], []], [$overview->incoming, $overview->outgoing]);
    }

    public function test_FR_FRND_03_capitals_with_accents_are_capitals_too(): void
    {
        // Once the name with the capital is stored first, once the other one.
        foreach (['Ödön', 'öde', 'ära', 'Ärger'] as $name) {
            $this->givenFriends($this->anna, $this->givenNamed($name));
        }

        $overview = $this->friendList()->show($this->anna->id);

        // "öde" is in front of "ödön", also when that one is written "Ödön".
        self::assertSame(['ära', 'Ärger', 'öde', 'Ödön'], array_map(static fn (FriendView $friend): string => $friend->user->displayName->value, $overview->friends));
    }

    public function test_FR_FRND_03_friends_with_the_same_name_keep_one_order(): void
    {
        $first = $this->givenUser('first@example.org');
        $second = $this->givenUser('second@example.org');
        // The one with the larger id was asked first, so the order of the requests is the other way round.
        $this->givenFriends($this->anna, $second, requestedAt: '2026-11-13 10:00:00');
        $this->givenFriends($this->anna, $first, requestedAt: '2026-11-15 10:00:00');

        $overview = $this->friendList()->show($this->anna->id);

        self::assertSame(
            [$first->id->value, $second->id->value],
            array_map(static fn (FriendView $friend): string => $friend->user->userId->value, $overview->friends),
        );
    }

    public function test_FR_FRND_03_what_a_friend_sees_is_what_was_stored_when_the_last_day_became_final(): void
    {
        $bob = $this->givenNamed('Bob');
        $this->givenFriends($this->anna, $bob);
        $this->givenOnboarded($bob, '2026-11-10', '2026-11-17', streaks: [7, 7, 7, 7, 1], totalXp: 260, level: 1);
        // Today is checked in and under the limit, which would give Bob 30 XP and a longer streak.
        $this->givenDay($bob, '2026-11-17', 2, '2026-11-17 11:00:00');

        $overview = $this->friendList()->show($this->anna->id);

        // The open day is Bob's own business until it is final.
        self::assertSame([['Bob', '2026-11-16 08:00:00', 1, 260, [7, 7, 7, 7]]], array_map(self::friend(...), $overview->friends));
    }

    public function test_FR_FRND_03_a_friend_sees_which_badges_a_friend_holds_and_not_when_they_were_earned(): void
    {
        $bob = $this->givenNamed('Bob');
        $carol = $this->givenNamed('Carol');
        $stranger = $this->givenNamed('Stranger');
        $this->givenFriends($this->anna, $bob);
        $this->givenFriends($carol, $this->anna);
        // Stored in another order than the badge cases have.
        $this->badgeRecords->add(new AwardedBadge($bob->id, Badge::LimitHalved, TestData::time('2026-11-16 23:00:00')));
        $this->badgeRecords->add(new AwardedBadge($bob->id, Badge::FirstCheckIn, TestData::time('2026-11-01 23:00:00')));
        $this->badgeRecords->add(new AwardedBadge($this->anna->id, Badge::Cheerleader, TestData::time('2026-11-02 23:00:00')));
        $this->badgeRecords->add(new AwardedBadge($stranger->id, Badge::FiveDuelsWon, TestData::time('2026-11-03 23:00:00')));

        $overview = $this->friendList()->show($this->anna->id);

        // The view of a friend holds the badges and no time. Carol has none; Anna's own are not Bob's.
        self::assertSame(['Bob', 'Carol'], array_map(static fn (FriendView $friend): string => $friend->user->displayName->value, $overview->friends));
        self::assertSame([[Badge::FirstCheckIn, Badge::LimitHalved], []], array_map(static fn (FriendView $friend): array => $friend->badges, $overview->friends));
    }

    public function test_FR_FRND_03_a_friend_sees_the_health_milestones_the_friends_own_setting_allows(): void
    {
        $bob = $this->givenNamed('Bob');
        $carol = $this->givenNamed('Carol');
        $dave = $this->givenNamed('Dave');
        $erin = $this->givenNamed('Erin');
        foreach ([$bob, $carol, $dave, $erin] as $friend) {
            $this->givenFriends($this->anna, $friend);
        }
        // All four have reached the same three, stored in another order than the milestones have.
        foreach ([$bob, $carol, $dave, $this->anna] as $user) {
            foreach ([HealthMilestone::Circulation, HealthMilestone::HeartRate, HealthMilestone::CarbonMonoxide] as $milestone) {
                $this->milestoneRecords->add(new ReachedMilestone($user->id, $milestone, TestData::time('2026-11-15 08:00:00')));
            }
        }
        // Bob never chose. Carol shows everything, Dave nothing. Erin has reached nothing.
        $this->userSettings->save($carol->id, new Settings(MilestoneSharing::All));
        $this->userSettings->save($dave->id, new Settings(MilestoneSharing::Private));
        $this->userSettings->save($erin->id, new Settings(MilestoneSharing::All));
        // Anna's own setting decides nothing about what she sees of others.
        $this->userSettings->save($this->anna->id, new Settings(MilestoneSharing::Private));

        $overview = $this->friendList()->show($this->anna->id);

        self::assertSame(
            [
                'Bob' => [HealthMilestone::Circulation],
                'Carol' => [HealthMilestone::HeartRate, HealthMilestone::CarbonMonoxide, HealthMilestone::Circulation],
                'Dave' => [],
                'Erin' => [],
            ],
            array_column(
                array_map(static fn (FriendView $friend): array => ['name' => $friend->user->displayName->value, 'milestones' => $friend->healthMilestones], $overview->friends),
                'milestones',
                'name',
            ),
        );
    }

    public function test_FR_FRND_01_somebody_with_an_open_request_shows_no_badges(): void
    {
        $asker = $this->givenNamed('Asker');
        $asked = $this->givenNamed('Asked');
        $this->givenRequest($asker, $this->anna);
        $this->givenRequest($this->anna, $asked);
        $this->badgeRecords->add(new AwardedBadge($asker->id, Badge::FirstCheckIn, TestData::time('2026-11-01 23:00:00')));
        $this->badgeRecords->add(new AwardedBadge($asked->id, Badge::FirstCheckIn, TestData::time('2026-11-01 23:00:00')));

        $overview = $this->friendList()->show($this->anna->id);

        // A request carries a public profile and a time, and nothing else: name and avatar only.
        self::assertSame([], $overview->friends);
        self::assertSame(
            [['userId', 'displayName', 'avatar'], ['userId', 'displayName', 'avatar']],
            [array_keys(get_object_vars($overview->incoming[0]->user)), array_keys(get_object_vars($overview->outgoing[0]->user))],
        );
        self::assertSame(['user', 'requestedAt'], array_keys(get_object_vars($overview->incoming[0])));
    }

    public function test_FR_FRND_01_open_requests_are_listed_apart_oldest_first_with_name_and_avatar_only(): void
    {
        $bob = $this->givenNamed('Bob');
        $carol = $this->givenNamed('Carol');
        $dave = $this->givenNamed('Dave');
        $erin = $this->givenNamed('Erin');
        $this->givenRequest($carol, $this->anna, '2026-11-16 10:00:00');
        $this->givenRequest($bob, $this->anna, '2026-11-15 10:00:00');
        $this->givenRequest($this->anna, $erin, '2026-11-16 12:00:00');
        $this->givenRequest($this->anna, $dave, '2026-11-14 12:00:00');
        $this->givenOnboarded($bob, totalXp: 640, level: 3);

        $overview = $this->friendList()->show($this->anna->id);

        self::assertSame([], $overview->friends);
        self::assertSame([['Bob', '2026-11-15 10:00:00'], ['Carol', '2026-11-16 10:00:00']], self::requests($overview->incoming));
        self::assertSame([['Dave', '2026-11-14 12:00:00'], ['Erin', '2026-11-16 12:00:00']], self::requests($overview->outgoing));
        self::assertSame([$bob->id->value, 'Bob', 'avatar-07'], self::profile($overview->incoming[0]->user));
    }

    public function test_FR_ACC_08_a_link_to_an_account_that_does_not_exist_any_more_is_left_out(): void
    {
        $bob = $this->givenNamed('Bob');
        $carol = $this->givenNamed('Carol');
        $dave = $this->givenNamed('Dave');
        $erin = $this->givenNamed('Erin');
        $this->givenFriends($this->anna, $bob);
        $this->givenFriends($this->anna, $carol);
        $this->givenRequest($dave, $this->anna);
        $this->givenRequest($this->anna, $erin);
        foreach ([$bob, $dave, $erin] as $gone) {
            $this->users->delete($gone->id);
        }

        $overview = $this->friendList()->show($this->anna->id);

        self::assertSame(['Carol'], array_map(static fn (FriendView $friend): string => $friend->user->displayName->value, $overview->friends));
        self::assertSame([[], []], [$overview->incoming, $overview->outgoing]);
    }

    public function test_FR_FRND_02_removing_a_friend_ends_the_friendship_for_both(): void
    {
        $bob = $this->givenNamed('Bob');
        $carol = $this->givenNamed('Carol');
        $this->givenFriends($bob, $this->anna);
        $other = $this->givenFriends($this->anna, $carol);

        $this->friendList()->remove($this->anna->id, $bob->id);

        self::assertEquals([$other], $this->friendLinks->all());
        self::assertSame([], $this->friendList()->show($bob->id)->friends);
        // Both accounts were locked, the smaller id first.
        self::assertSame([$this->anna->id->value, $bob->id->value], $this->users->lockLog);

        // And the other way round, by the one with the larger id.
        $this->users->lockLog = [];
        $this->friendList()->remove($carol->id, $this->anna->id);
        self::assertSame([], $this->friendLinks->all());
        self::assertSame([$this->anna->id->value, $carol->id->value], $this->users->lockLog);
    }

    public function test_FR_FRND_02_only_a_friend_can_be_removed(): void
    {
        $bob = $this->givenNamed('Bob');
        $carol = $this->givenNamed('Carol');
        $dave = $this->givenNamed('Dave');
        // An open request is not a friendship, whoever asked. It is declined or taken back instead.
        $incoming = $this->givenRequest($bob, $this->anna);
        $outgoing = $this->givenRequest($this->anna, $carol);

        foreach ([$bob, $carol, $dave] as $noFriend) {
            try {
                $this->friendList()->remove($this->anna->id, $noFriend->id);
                self::fail('Should have been refused.');
            } catch (FriendNotFound) {
                self::assertEquals([$incoming, $outgoing], $this->friendLinks->all());
            }
        }
    }

    public function test_FR_FRND_02_nobody_is_their_own_friend(): void
    {
        $this->expectException(FriendNotFound::class);

        $this->friendList()->remove($this->anna->id, $this->anna->id);
    }

    public function test_FR_ACC_08_a_friend_who_was_deleted_in_the_meantime_is_not_found_and_a_deleted_user_removes_nobody(): void
    {
        $bob = $this->givenNamed('Bob');
        $this->givenFriends($bob, $this->anna);
        $this->users->beforeLock = fn () => $this->users->delete($bob->id);

        try {
            $this->friendList()->remove($this->anna->id, $bob->id);
            self::fail('Should have been refused.');
        } catch (FriendNotFound) {
            $this->addToAssertionCount(1);
        }

        $this->users->delete($this->anna->id);
        $this->expectException(AccountNotFound::class);
        $this->friendList()->remove($this->anna->id, $bob->id);
    }
}
