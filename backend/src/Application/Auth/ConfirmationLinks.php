<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Mail\AccountMails;

/**
 * A new confirmation link for a registration that is waiting to be confirmed (FR-ACC-03): for
 * "send the link again", and for a login with the right password before the address is confirmed.
 */
final readonly class ConfirmationLinks
{
    public function __construct(
        private UserRepository $users,
        private OneTimeTokens $oneTimeTokens,
        private AccountMails $mails,
    ) {
    }

    /**
     * Inside the use case's transaction, which holds the lock on the account.
     *
     * The older link stops working. The new one is good for 24 hours, as its mail says, so the
     * registration is kept as long: its 24 hours start again, exactly as when it is submitted again.
     *
     * The mail with the new link is queued here, in the same transaction, and sent by the cron job.
     *
     * @param User $pending A registration for which isPendingAt($now) holds.
     */
    public function renew(User $pending, DateTimeImmutable $now): void
    {
        $this->users->update($pending->registeredAt($now));
        $token = $this->oneTimeTokens->issue($pending->id, TokenPurpose::VerifyEmail, $now);

        $this->mails->confirmEmail($pending, $token, $now);
    }
}
