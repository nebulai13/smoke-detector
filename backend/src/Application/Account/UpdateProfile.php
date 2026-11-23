<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\ValidationFailed;

/**
 * PATCH /me (HLD §4.5 "Account", FR-ACC-09): display name, avatar, language and time zone.
 *
 * The email address cannot be changed here: a new one would have to be confirmed first.
 */
final readonly class UpdateProfile
{
    public function __construct(
        private TransactionRunner $transactions,
        private UserRepository $users,
    ) {
    }

    /**
     * @throws ValidationFailed When nothing is to be changed: most likely a mistake of the client.
     * @throws AccountNotFound  When the account was deleted in the meantime.
     */
    public function execute(UserId $userId, ProfileChange $change): void
    {
        if ($change->isEmpty()) {
            throw ValidationFailed::forField('', ErrorCode::Required, 'At least one of displayName, avatar, locale and timeZone is required.');
        }

        $this->transactions->run(function () use ($userId, $change): void {
            // Under a lock: update() writes every column, and must not write back a password hash or
            // a confirmation that another request changed since the account was read.
            $user = $this->users->lock($userId) ?? throw new AccountNotFound();

            $this->users->update($change->applyTo($user));
        });
    }
}
