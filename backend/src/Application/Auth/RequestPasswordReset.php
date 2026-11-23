<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Mail\AccountMails;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /auth/password-reset/request (HLD §4.5 "Password reset", FR-ACC-05).
 *
 * The caller learns nothing about the address. Only a confirmed account gets a link. An unconfirmed
 * registration gets none: the reset would be a second way to activate an account, around the
 * confirmation link, for a registration (and its consents) that the mailbox owner may never have made.
 *
 * The mail is queued in the transaction that stores the link, and sent by the cron job
 * (AccountMails): a link and its mail cannot come apart, and nothing here waits for a mail server.
 */
final readonly class RequestPasswordReset
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private MailBudget $mailBudget,
        private UserRepository $users,
        private OneTimeTokens $oneTimeTokens,
        private AccountMails $mails,
    ) {
    }

    /**
     * @param string $clientAddress Where the request comes from, for the rate limit.
     *
     * @throws RateLimitExceeded When the client address has asked for too many mails (see MailBudget).
     */
    public function execute(EmailAddress $email, string $clientAddress): void
    {
        $now = $this->clock->now();
        if (!$this->mailBudget->spend($email, $clientAddress, $now)) {
            // No mail this hour, so no new link either: the one of the last mail keeps working.
            return;
        }

        // Which account to lock is looked up before the transaction, never inside it: MariaDB 11.8
        // gives a transaction up (error 1020) when it locks a row that was changed after the
        // transaction's first plain read, which is exactly what a request a moment earlier does.
        $found = $this->users->findByEmail($email);
        $this->transactions->run(function () use ($found, $now): void {
            // Locked, so that two requests at once leave exactly one working link behind. What is
            // decided below is decided on the row as it is under the lock.
            $user = $found === null ? null : $this->users->lock($found->id);
            if ($user === null || !$user->isVerified()) {
                return;
            }

            // Withdraws the link of an earlier request.
            $token = $this->oneTimeTokens->issue($user->id, TokenPurpose::ResetPassword, $now);

            $this->mails->resetPassword($user, $token, $now);
        });
    }
}
