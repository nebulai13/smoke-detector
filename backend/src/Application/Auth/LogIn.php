<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /auth/login (HLD §4.5 "Login and tokens", FR-ACC-01, FR-ACC-03, FR-ACC-04, NFR-05).
 *
 * There is one answer to a login that does not succeed, whatever the reason: the address is
 * unknown, the password is wrong, or the password is right but the registration is not confirmed
 * yet. The third must not differ from the first two in anything a client can see (status, body,
 * headers, the count towards the limit): somebody could otherwise register any address with a
 * password of their own, log in with it, and read from the answer whether a confirmed account was
 * there before. Whoever really registered and has not confirmed yet is helped by mail instead: a
 * right password queues a mail with a fresh confirmation link (the cron job sends it, so the
 * answer does not wait for a mail server either).
 *
 * Rate limit: see FailedLogins. Five failures per 15 minutes lock a client for the address it
 * guessed at, not the address for everybody. Every attempt is counted before the password is
 * looked at, and the count is taken back only when that same attempt opened a session. So attempts
 * that arrive at the same moment cannot outrun the counter, and a successful login takes back
 * nothing but its own count: it does not forgive the failures before it, for any account.
 */
final readonly class LogIn
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private FailedLogins $failedLogins,
        private RateLimiter $limiter,
        private PasswordHasher $hasher,
        private UserRepository $users,
        private Sessions $sessions,
        private ConfirmationLinks $confirmationLinks,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $password      Exactly as typed.
     * @param string $clientAddress Where the request comes from, for the rate limit.
     *
     * @throws RateLimitExceeded  After too many failures (see FailedLogins), whatever the password.
     * @throws InvalidCredentials When the email address is unknown, the password is wrong, or the
     *                            registration is not confirmed.
     */
    public function execute(
        EmailAddress $email,
        #[SensitiveParameter]
        string $password,
        ?DeviceLabel $deviceLabel,
        string $clientAddress,
    ): Session {
        $now = $this->clock->now();
        $this->failedLogins->count($email, $clientAddress, $now);

        $user = $this->users->findByEmail($email);
        // The check runs in every case, against a dummy hash when there is no account, so that the
        // answer takes as long for an unknown address as for a known one.
        $correct = $this->hasher->verify($password, $user === null ? $this->hasher->dummyHash() : $user->passwordHash);
        if ($user === null || !$correct) {
            throw new InvalidCredentials();
        }

        if (!$user->isVerified()) {
            // Nothing is taken back from the counters: the sixth such attempt is refused like the
            // sixth wrong password, or the limit would tell the two apart.
            $this->mailConfirmationLink($user, $clientAddress, $now);

            throw new InvalidCredentials();
        }

        $session = $this->transactions->run(function () use ($user, $password, $deviceLabel, $now): ?Session {
            // Read again under a lock: if the password was changed or the account deleted while the
            // old password was being checked, that password must not open a session any more, and
            // must not be written back as a "fresher" hash.
            $current = $this->users->lock($user->id);
            if ($current === null || $current->passwordHash !== $user->passwordHash) {
                return null;
            }

            if ($this->hasher->needsRehash($current->passwordHash)) {
                $current = $current->withPasswordHash($this->hasher->hash($password));
                $this->users->update($current);
            }

            return $this->sessions->start($current, $deviceLabel, $now);
        });
        if ($session === null) {
            throw new InvalidCredentials();
        }

        // The attempt was not a failure. The session is committed: if the database fails now, the
        // login stands all the same, and the attempt stays counted until its window is over.
        try {
            $this->failedLogins->takeBack($email, $clientAddress, $now);
        } catch (RuntimeException $failure) {
            // The class only: the message may name the address.
            $this->logger->warning('A rate limit counter could not be updated after the work was done.', ['exception' => $failure::class]);
        }

        return $session;
    }

    /**
     * For a registration that is waiting to be confirmed and whose password was just entered
     * correctly: a new link, as "send the link again" would mail it. At most 5 such mails per hour
     * are caused by one client and 1 per hour goes to one address. Over a limit the mail is left
     * out without a word: a 429 here would be an answer that only an unconfirmed registration gets.
     * For the same reason these are counters of their own (see AuthLimits).
     */
    private function mailConfirmationLink(User $user, string $clientAddress, DateTimeImmutable $now): void
    {
        if (
            !$user->isPendingAt($now)
            || !$this->limiter->tryConsume(AuthLimits::confirmationMailsByLoginPerClient(), $clientAddress, $now)
            || !$this->limiter->tryConsume(AuthLimits::confirmationMailsByLoginPerEmail(), $user->email->value, $now)
        ) {
            return;
        }

        $this->transactions->run(function () use ($user, $now): void {
            // Read again under a lock: the link is for the registration whose password was checked,
            // not for one that was confirmed, replaced or deleted in the meantime.
            $current = $this->users->lock($user->id);
            if ($current === null || !$current->isPendingAt($now) || $current->passwordHash !== $user->passwordHash) {
                return;
            }

            $this->confirmationLinks->renew($current, $now);
        });
    }
}
