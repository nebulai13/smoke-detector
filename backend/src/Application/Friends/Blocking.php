<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /friends/block and /friends/unblock, GET /friends/blocked (FR-FRND-02, HLD §4.7 "Blocks").
 *
 * A user can block whom they can see: a friend, or somebody with an open request in either
 * direction. The block deletes the friendship or the request and hides the two from each other.
 * Only the blocker sees the block and can lift it; nobody learns who has blocked them.
 */
final readonly class Blocking
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private Blocks $blocks,
    ) {
    }

    /**
     * Blocking somebody who is blocked already changes nothing.
     *
     * @throws FriendNotFound  No friendship and no open request between the two, or the other
     *                         account does not exist (any more). Also when only the other one has
     *                         blocked the user: a block deletes every link, and none can be made
     *                         while it lasts.
     * @throws AccountNotFound When the account of $userId was deleted in the meantime.
     */
    public function block(UserId $userId, UserId $other): void
    {
        $now = $this->clock->now();

        $this->transactions->run(function () use ($userId, $other, $now): void {
            if (LockedPair::lock($this->users, $userId, $other) === null) {
                throw new FriendNotFound();
            }
            if ($this->blocks->isBlocked($userId, $other)) {
                return;
            }
            if ($this->links->between($userId, $other) === null) {
                throw new FriendNotFound();
            }
            $this->links->delete($userId, $other);
            $this->blocks->add(new Block($userId, $other, $now));
        });
    }

    /**
     * Lifts the user's own block. The two are not friends again by this. Changes a row of the
     * blocker alone, so only that account is locked.
     *
     * @throws BlockNotFound   The user has not blocked $blocked.
     * @throws AccountNotFound When the account of $userId was deleted in the meantime.
     */
    public function unblock(UserId $userId, UserId $blocked): void
    {
        $this->transactions->run(function () use ($userId, $blocked): void {
            $this->users->lock($userId) ?? throw new AccountNotFound();
            if (!$this->blocks->remove($userId, $blocked)) {
                throw new BlockNotFound();
            }
        });
    }

    /**
     * @return list<BlockedUser> Whom the user has blocked, oldest block first. A user whose account
     *                           does not exist (any more) is left out. Locks nothing.
     */
    public function blocked(UserId $userId): array
    {
        return $this->transactions->run(function () use ($userId): array {
            $blocked = [];
            foreach ($this->blocks->byUser($userId) as $block) {
                $user = $this->users->find($block->blocked);
                if ($user !== null) {
                    $blocked[] = new BlockedUser(PublicProfile::of($user), $block->blockedAt);
                }
            }

            return $blocked;
        });
    }
}
