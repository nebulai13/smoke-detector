<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use SmokeDetector\Application\Account\UserId;

/**
 * What happens to the duels of two users who stop being friends (HLD §4.8 "Friends", decided by the
 * owner): their proposal is deleted, also one whose time is over, and their running duel ends
 * without a winner and without XP. Duels that are over stay as they are.
 *
 * Called by FriendList::remove and Blocking::block inside their transaction, after both accounts
 * are locked. It starts no transaction of its own.
 */
final readonly class EndDuels
{
    public function __construct(private Duels $duels)
    {
    }

    public function between(UserId $userId, UserId $other): void
    {
        foreach ($this->duels->openOf($userId) as $duel) {
            if (!$duel->isOf($other)) {
                continue;
            }
            if ($duel->status === DuelStatus::Proposed) {
                $this->duels->delete($duel->id);
            } else {
                $this->duels->save($duel->withStatus(DuelStatus::Cancelled));
            }
        }
    }
}
