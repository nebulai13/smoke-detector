<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\TransactionRunner;

/**
 * GET /friends and POST /friends/remove (FR-FRND-02, FR-FRND-03, HLD §4.7): the friends of a user
 * with what friends see of each other, the open requests apart, and the end of a friendship.
 */
final readonly class FriendList
{
    public function __construct(
        private TransactionRunner $transactions,
        private UserRepository $users,
        private FriendLinks $links,
        private ProgressRecords $progress,
    ) {
    }

    /**
     * Friends by name without regard to capitals, then by user id; requests oldest first. A link
     * whose other account does not exist (any more) is left out. Locks nothing: one transaction
     * only so that everything is read from one snapshot.
     */
    public function show(UserId $userId): FriendOverview
    {
        return $this->transactions->run(function () use ($userId): FriendOverview {
            $friends = [];
            $incoming = [];
            $outgoing = [];
            foreach ($this->links->ofUser($userId) as $link) {
                $otherId = $link->other($userId);
                $other = $this->users->find($otherId);
                if ($other === null) {
                    continue;
                }
                if ($link->acceptedAt !== null) {
                    // The stored progress, never the projection: a friend's open days are the friend's own business.
                    $friends[] = FriendView::of($other, $link->acceptedAt, $this->progress->find($otherId));
                } elseif ($link->requester->equals($userId)) {
                    $outgoing[] = new PendingRequest(PublicProfile::of($other), $link->requestedAt);
                } else {
                    $incoming[] = new PendingRequest(PublicProfile::of($other), $link->requestedAt);
                }
            }
            usort(
                $friends,
                static fn (FriendView $a, FriendView $b): int => strcmp(mb_strtolower($a->user->displayName->value), mb_strtolower($b->user->displayName->value))
                    ?: strcmp($a->user->userId->value, $b->user->userId->value),
            );

            return new FriendOverview($friends, $incoming, $outgoing);
        });
    }

    /**
     * Ends the friendship for both. An open request is not removed this way: it is declined or
     * taken back.
     *
     * @throws FriendNotFound  The two are not friends, or the other account does not exist (any more).
     * @throws AccountNotFound When the account of $userId was deleted in the meantime.
     */
    public function remove(UserId $userId, UserId $friend): void
    {
        $this->transactions->run(function () use ($userId, $friend): void {
            $account = LockedPair::lock($this->users, $userId, $friend);
            if ($account === null || !($this->links->between($userId, $friend)?->isAccepted() ?? false)) {
                throw new FriendNotFound();
            }
            $this->links->delete($userId, $friend);
        });
    }
}
