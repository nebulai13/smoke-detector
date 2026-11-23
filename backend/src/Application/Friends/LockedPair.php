<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;

/**
 * The locks of a change between two users (HLD §4.7 "Locks"): both accounts, the smaller id (as
 * text) first, whoever acts. Two requests that cross each other cannot wait for each other, and
 * two acceptances at the same moment cannot pass the limit together.
 */
final readonly class LockedPair
{
    /**
     * Locks both accounts. Only inside TransactionRunner::run(), as its first reads.
     *
     * @return User|null The other account, or null when it does not exist (any more).
     *
     * @throws AccountNotFound When the account of $userId does not exist (any more), whether or not
     *                         the other one does.
     */
    public static function lock(UserRepository $users, UserId $userId, UserId $other): ?User
    {
        // By the id as text, the way PHP compares strings. One lock when both are the same.
        $ids = [$userId->value => $userId, $other->value => $other];
        ksort($ids, SORT_STRING);
        $accounts = array_map($users->lock(...), $ids);

        if ($accounts[$userId->value] === null) {
            throw new AccountNotFound();
        }

        return $accounts[$other->value];
    }
}
