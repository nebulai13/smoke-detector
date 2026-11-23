<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Badge\BadgeRecords;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Recovery\MilestoneRecords;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Application\TransactionRunner;

/**
 * Friend requests (FR-FRND-01, FR-FRND-04, HLD §4.7 "Requests" and "Limit"): sent with the invite
 * code of the other user, who accepts or declines; the sender can take an open request back.
 *
 * Every operation locks both accounts first, the smaller id first (LockedPair). An account that
 * does not exist (any more) on the other side is answered like a link that does not exist.
 */
final readonly class FriendRequests
{
    /** FR-FRND-04: nobody has more friends. Also the maxItems of the lists in api/openapi.yaml. */
    public const int MAX_FRIENDS = 50;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private InviteCodes $codes,
        private FriendLinks $links,
        private Blocks $blocks,
        private ProgressRecords $progress,
        private BadgeRecords $badges,
        private MilestoneRecords $milestones,
        private UserSettings $settings,
    ) {
    }

    /**
     * POST /friends/requests. Sent again, nothing changes. When the owner of the code had asked the
     * sender before, both have agreed: the owner's request is accepted.
     *
     * @throws InviteNotFound     Nobody has the code, or one of the two has blocked the other.
     * @throws OwnInvite          The code is the sender's own.
     * @throws AlreadyFriends     The two are friends already.
     * @throws FriendLimitReached The sender's friends and own open requests are 50 already.
     * @throws AccountNotFound    When the sender's account was deleted in the meantime.
     */
    public function send(UserId $sender, InviteCode $code): SentRequest
    {
        $now = $this->clock->now();
        // The read that tells which account to lock runs before the transaction (HLD §4.5
        // "Transactions and locks"). Inside, after the locks, the code must still be the owner's.
        $owner = $this->codes->owner($code) ?? throw new InviteNotFound();

        return $this->transactions->run(function () use ($sender, $code, $owner, $now): SentRequest {
            $ownerAccount = LockedPair::lock($this->users, $sender, $owner);
            if ($ownerAccount === null || $this->codes->owner($code)?->equals($owner) !== true) {
                throw new InviteNotFound();
            }
            if ($owner->equals($sender)) {
                throw new OwnInvite();
            }
            // For both, the other one's code seems not to exist.
            if ($this->blocks->between($sender, $owner)) {
                throw new InviteNotFound();
            }

            $profile = PublicProfile::of($ownerAccount);
            $link = $this->links->between($sender, $owner);
            if ($link === null) {
                $this->assertRoom($sender);
                $this->links->save(new Friendship($sender, $owner, $now));

                return new SentRequest(false, $profile);
            }
            if ($link->isAccepted()) {
                throw new AlreadyFriends();
            }
            // The sender's own request, sent again: no limit to check, nothing to change.
            if ($link->requester->equals($sender)) {
                return new SentRequest(false, $profile);
            }

            $this->assertRoom($sender);
            $this->links->save($link->acceptedAt($now));

            return new SentRequest(true, $profile);
        });
    }

    /**
     * POST /friends/requests/accept: $userId accepts the open request of $requester.
     *
     * @return FriendView The new friend, as $userId sees them from now on.
     *
     * @throws FriendRequestNotFound No open request of $requester to $userId.
     * @throws FriendLimitReached    The friends and own open requests of $userId are 50 already.
     * @throws AccountNotFound       When the account of $userId was deleted in the meantime.
     */
    public function accept(UserId $userId, UserId $requester): FriendView
    {
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $requester, $now): FriendView {
            [$account, $request] = $this->openRequest($userId, $requester, $requester);
            $this->assertRoom($userId);
            $this->links->save($request->acceptedAt($now));

            // The badges and health milestones of the new friend, as GET /friends shows them
            // (FriendList, FR-FRND-03): the milestones as the new friend's own setting allows.
            $badges = $this->badges->heldBy([$requester]);
            $milestones = $this->milestones->reachedBy([$requester]);

            return FriendView::of(
                $account,
                $now,
                $this->progress->find($requester),
                $badges[$requester->value] ?? [],
                $milestones[$requester->value] ?? [],
                $this->settings->find($requester)->milestoneSharing,
            );
        });
    }

    /**
     * POST /friends/requests/decline: $userId declines the open request of $requester. It is
     * deleted; the one who asked is not told and can ask again.
     *
     * @throws FriendRequestNotFound No open request of $requester to $userId.
     * @throws AccountNotFound       When the account of $userId was deleted in the meantime.
     */
    public function decline(UserId $userId, UserId $requester): void
    {
        $this->transactions->run(function () use ($userId, $requester): void {
            $this->openRequest($userId, $requester, $requester);
            $this->links->delete($userId, $requester);
        });
    }

    /**
     * POST /friends/requests/withdraw: $userId takes back the open request to $recipient.
     *
     * @throws FriendRequestNotFound No open request of $userId to $recipient.
     * @throws AccountNotFound       When the account of $userId was deleted in the meantime.
     */
    public function withdraw(UserId $userId, UserId $recipient): void
    {
        $this->transactions->run(function () use ($userId, $recipient): void {
            $this->openRequest($userId, $recipient, $userId);
            $this->links->delete($userId, $recipient);
        });
    }

    /**
     * Locks both accounts and finds the open request between $userId and $other that $requester
     * has sent.
     *
     * @return array{User, Friendship} The account of $other, and the request.
     *
     * @throws FriendRequestNotFound
     * @throws AccountNotFound
     */
    private function openRequest(UserId $userId, UserId $other, UserId $requester): array
    {
        $account = LockedPair::lock($this->users, $userId, $other);
        $request = $this->links->between($userId, $other);
        if ($account === null || $request === null || !$request->isOpenRequestOf($requester)) {
            throw new FriendRequestNotFound();
        }

        return [$account, $request];
    }

    /**
     * The limit (FR-FRND-04, HLD §4.7 "Limit"): the user's friends and own open requests together
     * stay below 50, checked for the one who asks or accepts. Requests of others to the user do not
     * count, or strangers with the code could fill the list; the other side's numbers are never
     * looked at, so a refusal tells nothing about them.
     */
    private function assertRoom(UserId $user): void
    {
        $taken = array_filter(
            $this->links->ofUser($user),
            static fn (Friendship $link): bool => $link->isAccepted() || $link->requester->equals($user),
        );
        if (count($taken) >= self::MAX_FRIENDS) {
            throw new FriendLimitReached();
        }
    }
}
