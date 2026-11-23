<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application;

use DateTimeImmutable;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\EndDuels;
use SmokeDetector\Application\Friends\Block;
use SmokeDetector\Application\Friends\Blocking;
use SmokeDetector\Application\Friends\FriendList;
use SmokeDetector\Application\Friends\FriendRequests;
use SmokeDetector\Application\Friends\Friendship;
use SmokeDetector\Application\Friends\FriendView;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\Invites;
use SmokeDetector\Application\Friends\PendingRequest;
use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Tests\Support\Fake\SequenceInviteCodes;
use SmokeDetector\Tests\Support\InMemory\InMemoryBlocks;
use SmokeDetector\Tests\Support\InMemory\InMemoryFriendLinks;
use SmokeDetector\Tests\Support\InMemory\InMemoryInviteCodes;

/**
 * The world of the friends (HLD §4.7), without any I/O: what logging has, plus in-memory invite
 * codes, friend links and blocks.
 *
 * It is noon UTC on Tuesday, 17 November 2026. Accounts get their ids in the order they are made,
 * so the first user of a test has the smallest id.
 */
abstract class FriendsUseCaseTestCase extends LoggingUseCaseTestCase
{
    protected InMemoryInviteCodes $inviteCodes;
    protected InMemoryFriendLinks $friendLinks;
    protected InMemoryBlocks $blocks;
    protected SequenceInviteCodes $codes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inviteCodes = new InMemoryInviteCodes($this->transactions);
        $this->friendLinks = new InMemoryFriendLinks($this->transactions);
        $this->blocks = new InMemoryBlocks($this->transactions);
        $this->codes = new SequenceInviteCodes();
    }

    protected function invites(): Invites
    {
        return new Invites($this->transactions, $this->clock, $this->users, $this->inviteCodes, $this->codes);
    }

    protected function friendRequests(): FriendRequests
    {
        return new FriendRequests($this->transactions, $this->clock, $this->users, $this->inviteCodes, $this->friendLinks, $this->blocks, $this->progressRecords, $this->badgeRecords);
    }

    protected function friendList(): FriendList
    {
        return new FriendList($this->transactions, $this->users, $this->friendLinks, $this->progressRecords, $this->endDuels(), $this->badgeRecords);
    }

    protected function blocking(): Blocking
    {
        return new Blocking($this->transactions, $this->clock, $this->users, $this->friendLinks, $this->blocks, $this->endDuels());
    }

    /**
     * What ends the duels of two users who stop being friends (HLD §4.8).
     */
    protected function endDuels(): EndDuels
    {
        return new EndDuels($this->duels);
    }

    /**
     * An account with a name of its own and the avatar "avatar-07".
     */
    protected function givenNamed(string $name): User
    {
        $user = $this->givenUser(strtolower(preg_replace('/[^A-Za-z]/', '', $name) ?? '') . count($this->users->all()) . '@example.org');
        $named = $user->withProfile(DisplayName::fromInput($name), $user->avatar, $user->locale, $user->timeZone);
        $this->users->update($named);

        return $named;
    }

    /**
     * $user owns this invite code since an hour ago.
     */
    protected function givenCode(User $user, string $code = 'K7M2QX9RTB'): InviteCode
    {
        $inviteCode = InviteCode::fromInput($code);
        self::assertTrue($this->inviteCodes->assign($user->id, $inviteCode, $this->now()->modify('-1 hour')));

        return $inviteCode;
    }

    /**
     * $from has asked $to, and nobody has answered yet.
     */
    protected function givenRequest(User $from, User $to, string $requestedAt = '2026-11-15 10:00:00'): Friendship
    {
        $request = new Friendship($from->id, $to->id, new DateTimeImmutable($requestedAt . ' UTC'));
        $this->friendLinks->save($request);

        return $request;
    }

    /**
     * $from has asked $to, and $to has accepted.
     */
    protected function givenFriends(User $from, User $to, string $acceptedAt = '2026-11-16 08:00:00', string $requestedAt = '2026-11-15 10:00:00'): Friendship
    {
        $friendship = new Friendship($from->id, $to->id, new DateTimeImmutable($requestedAt . ' UTC'), new DateTimeImmutable($acceptedAt . ' UTC'));
        $this->friendLinks->save($friendship);

        return $friendship;
    }

    /**
     * Gives $user this many friends more: accounts made for it, half of them asked by $user and
     * half of them the ones who asked.
     */
    protected function givenFriendsMore(User $user, int $howMany): void
    {
        for ($i = 0; $i < $howMany; $i++) {
            $other = $this->givenUser('friend' . count($this->users->all()) . '@example.org');
            $i % 2 === 0 ? $this->givenFriends($user, $other) : $this->givenFriends($other, $user);
        }
    }

    /**
     * Gives $user this many requests more that $user has sent and nobody has answered.
     */
    protected function givenOutgoingMore(User $user, int $howMany): void
    {
        for ($i = 0; $i < $howMany; $i++) {
            $this->givenRequest($user, $this->givenUser('asked' . count($this->users->all()) . '@example.org'));
        }
    }

    /**
     * Gives $user this many requests more that others have sent to $user.
     */
    protected function givenIncomingMore(User $user, int $howMany): void
    {
        for ($i = 0; $i < $howMany; $i++) {
            $this->givenRequest($this->givenUser('asking' . count($this->users->all()) . '@example.org'), $user);
        }
    }

    protected function givenBlock(User $blocker, User $blocked, string $blockedAt = '2026-11-16 09:00:00'): Block
    {
        $block = new Block($blocker->id, $blocked->id, new DateTimeImmutable($blockedAt . ' UTC'));
        $this->blocks->add($block);

        return $block;
    }

    /**
     * @return array{string, string, string} Id, name and avatar.
     */
    protected static function profile(PublicProfile $profile): array
    {
        return [$profile->userId->value, $profile->displayName->value, $profile->avatar->key];
    }

    /**
     * @return array{string, string, int, int, array{int, int, int, int}} Name, friends since, level,
     *                                                                     XP, and the streaks: check-in
     *                                                                     current and longest,
     *                                                                     under-limit current and longest.
     */
    protected static function friend(FriendView $friend): array
    {
        return [
            $friend->user->displayName->value,
            $friend->since->format('Y-m-d H:i:s'),
            $friend->level,
            $friend->xp,
            [$friend->checkInCurrent, $friend->checkInLongest, $friend->underLimitCurrent, $friend->underLimitLongest],
        ];
    }

    /**
     * @param list<PendingRequest> $requests
     *
     * @return list<array{string, string}> Name and time of each request.
     */
    protected static function requests(array $requests): array
    {
        return array_map(
            static fn (PendingRequest $request): array => [$request->user->displayName->value, $request->requestedAt->format('Y-m-d H:i:s')],
            $requests,
        );
    }
}
