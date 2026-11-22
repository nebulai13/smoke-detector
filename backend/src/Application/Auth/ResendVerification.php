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
 * POST /auth/resend-verification (HLD §4.5 "Registration" step 6, FR-ACC-03).
 *
 * The caller learns nothing about the address. An unconfirmed registration gets a new link (the
 * older one stops working, and its 24 hours start again), a confirmed account is told that it
 * exists, an unknown address gets nothing, and so does a registration whose 24 hours are over: it
 * is to be deleted and must not come back to life.
 *
 * The mail is queued in the transaction that stores the new link, and sent by the cron job
 * (AccountMails): a link and its mail cannot come apart, and nothing here waits for a mail server.
 */
final readonly class ResendVerification
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private MailBudget $mailBudget,
        private UserRepository $users,
        private ConfirmationLinks $confirmationLinks,
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
            if ($user === null) {
                return;
            }
            if ($user->isVerified()) {
                $this->mails->alreadyRegistered($user, $now);
            } elseif ($user->isPendingAt($now)) {
                $this->confirmationLinks->renew($user, $now);
            }
        });
    }
}
