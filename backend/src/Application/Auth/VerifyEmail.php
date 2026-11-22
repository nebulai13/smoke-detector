<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /auth/verify-email: the link of the confirmation mail, together with the password that was
 * chosen at sign-up (HLD §4.5 "Registration", FR-ACC-01, FR-ACC-03).
 *
 * The link alone confirms nothing. Anybody can register any address, as often as they like, and the
 * newest link always belongs to the newest submission: with the link alone, the owner of the
 * mailbox would activate the password of whoever registered the address last. So the account is
 * confirmed only when the token is valid AND the password is the one of the registration that is
 * waiting. Whoever proves both is the one who signed up and holds the mailbox, and gets a session
 * right away, exactly as from a login.
 *
 * A wrong password confirms nothing, gives nobody a session and says nothing about the
 * registration. The link stays usable, for five wrong passwords at most: the fifth deletes it.
 * A wrong password also counts as a failed login of the client (AuthLimits). The address has no
 * count here: the five tries of the link are the limit, and a new link takes a new mail.
 */
final readonly class VerifyEmail
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private RateLimiter $limiter,
        private PasswordHasher $hasher,
        private OneTimeTokens $oneTimeTokens,
        private UserRepository $users,
        private Sessions $sessions,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $password      Exactly as typed.
     * @param string $clientAddress Where the request comes from, for the rate limit.
     *
     * @throws InvalidToken       When the token is unknown, expired (24 h), already used or out of
     *                            tries, or its registration is older than 24 hours or confirmed
     *                            already. Decided before any password is hashed.
     * @throws RateLimitExceeded  When the client has too many failed logins, whatever the password.
     * @throws InvalidCredentials When the token is fine and the password is not the one of the
     *                            registration. Nothing is confirmed.
     */
    public function execute(
        #[SensitiveParameter]
        string $token,
        #[SensitiveParameter]
        string $password,
        ?DeviceLabel $deviceLabel,
        string $clientAddress,
    ): Session {
        $now = $this->clock->now();

        // Read without a lock first. A token that is worth nothing is refused here, before anything
        // is counted and before the password check, which is the expensive part: the endpoint is
        // public, and must not be a way to keep the server busy hashing.
        $owner = $this->oneTimeTokens->ownerOfUsable(TokenPurpose::VerifyEmail, $token, $now);
        $user = $owner === null ? null : $this->users->find($owner);
        if ($user === null || !$user->isPendingAt($now)) {
            throw new InvalidToken();
        }

        // Counted before the password is looked at and taken back only when it confirmed the
        // account, as in LogIn. The client first: over its limit, it uses up none of the link's tries.
        $this->limiter->consume(AuthLimits::loginFailuresPerClient(), $clientAddress, $now);
        $lastAttempt = $this->transactions->run(function () use ($user, $token, $now): bool {
            // The account first, then the token: the order every use case locks in (registering
            // again holds the account and then replaces its token).
            if ($this->users->lock($user->id) === null) {
                throw new InvalidToken();
            }

            return $this->oneTimeTokens->countAttempt(TokenPurpose::VerifyEmail, $token, $now);
        });

        if (!$this->hasher->verify($password, $user->passwordHash)) {
            if ($lastAttempt) {
                $this->transactions->run(function () use ($user, $token): void {
                    $this->users->lock($user->id);
                    $this->oneTimeTokens->withdraw(TokenPurpose::VerifyEmail, $token);
                });
            }

            throw new InvalidCredentials();
        }

        $session = $this->transactions->run(function () use ($user, $token, $deviceLabel, $now): Session {
            // Read again under a lock: the password that was just checked must still be the one of
            // the registration that is waiting. If the address was registered again in the meantime,
            // was confirmed by another request or is gone, there is nothing left to confirm with it.
            $current = $this->users->lock($user->id);
            if ($current === null || !$current->isPendingAt($now) || $current->passwordHash !== $user->passwordHash) {
                throw new InvalidToken();
            }
            // Thrown inside the transaction, a refusal here leaves everything as it was.
            $this->oneTimeTokens->redeem(TokenPurpose::VerifyEmail, $token, $now);

            $confirmed = $current->verifiedAt($now);
            $this->users->update($confirmed);

            return $this->sessions->start($confirmed, $deviceLabel, $now);
        });

        // The attempt was not a failure. The confirmation is committed: if the database fails now,
        // it stands all the same, and the attempt stays counted until its window is over.
        try {
            $this->limiter->refund(AuthLimits::loginFailuresPerClient(), $clientAddress, $now);
        } catch (RuntimeException $failure) {
            // The class only: the message may name the address.
            $this->logger->warning('A rate limit counter could not be updated after the work was done.', ['exception' => $failure::class]);
        }

        return $session;
    }
}
