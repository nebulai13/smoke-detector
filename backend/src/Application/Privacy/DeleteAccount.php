<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Privacy;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Auth\FailedLogins;
use SmokeDetector\Application\Auth\PasswordHasher;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\TransactionRunner;

/**
 * DELETE /me (HLD §4.5 "Account", FR-ACC-08, LEG-03): the user enters the password again, and
 * everything stored about the user is removed at once. Nothing is kept and nothing can be restored.
 *
 * The eraser of every table of the UserDataRegistry runs, then the account row is deleted, all in
 * one transaction: either the user is gone completely or nothing has changed. With the rows of
 * `refresh_tokens` every session ends, and Authenticate refuses the access tokens still in
 * circulation, because their account does not exist any more.
 *
 * Rate limit: a wrong password here is a failed login. It is counted under the same three rules as
 * POST /auth/login (FailedLogins), with the account's email address and the client the request
 * comes from. So the two endpoints together allow a client five guesses at a password, not five
 * each. One stranger guessing from one client address does not keep the owner from deleting the
 * account. A stranger with a few client addresses can, for up to an hour: the rule per email
 * address over all clients (50 failures per hour) locks the address here as at the login
 * (SRS OQ-15).
 */
final readonly class DeleteAccount
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private FailedLogins $failedLogins,
        private RateLimiter $limiter,
        private PasswordHasher $hasher,
        private UserRepository $users,
        private UserDataRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $password      The current password, exactly as typed.
     * @param string $clientAddress Where the request comes from, for the rate limit.
     *
     * @throws RateLimitExceeded After too many wrong passwords (see FailedLogins), whatever the password.
     * @throws WrongPassword     When the password is not the account's. Nothing is deleted.
     * @throws AccountNotFound   When the account was deleted in the meantime.
     */
    public function execute(UserId $userId, #[SensitiveParameter] string $password, string $clientAddress): void
    {
        $now = $this->clock->now();
        $user = $this->users->find($userId) ?? throw new AccountNotFound();

        // Counted before the password is looked at and taken back when it was right, as in LogIn:
        // attempts that arrive at the same moment cannot outrun the counter.
        $this->failedLogins->count($user->email, $clientAddress, $now);

        if (!$this->hasher->verify($password, $user->passwordHash)) {
            throw new WrongPassword();
        }

        $deleted = $this->transactions->run(function () use ($user): bool {
            // Read again under a lock: nothing else may change the account while it is taken apart,
            // and a password that was replaced while the old one was being checked confirms nothing.
            $current = $this->users->lock($user->id) ?? throw new AccountNotFound();
            if ($current->passwordHash !== $user->passwordHash) {
                return false;
            }

            $this->registry->erase($current->id);
            $this->users->delete($current->id);

            return true;
        });
        if (!$deleted) {
            throw new WrongPassword();
        }

        // After the commit, because counters are not part of any transaction. The attempt was not
        // a failure, and what was counted about this person (keyed by the hash of the email address,
        // alone or next to a client address, or of the user id) goes as well. What was counted for
        // a client address over all email addresses is not the user's alone and stays.
        //
        // The account is gone: a failure here must not reach the caller, who could only be told
        // that there is nothing left to delete. Each step is tried on its own, so one failure does
        // not keep the others from deleting what they can. What is left is deleted by the cleanup
        // within two days (PurgeExpiredData).
        $this->counterWork(fn () => $this->failedLogins->takeBack($user->email, $clientAddress, $now));
        $this->counterWork(fn () => $this->limiter->forget($user->email->value));
        $this->counterWork(fn () => $this->limiter->forget($user->id->value));
    }

    /**
     * @param callable(): void $work Work on the counters, after the commit.
     */
    private function counterWork(callable $work): void
    {
        try {
            $work();
        } catch (RuntimeException $failure) {
            // The class only: the message may name the address.
            $this->logger->warning('A rate limit counter could not be updated after the work was done.', ['exception' => $failure::class]);
        }
    }
}
