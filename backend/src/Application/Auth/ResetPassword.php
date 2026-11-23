<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Application\Validation\ValidationFailed;

/**
 * POST /auth/password-reset/confirm: the link of the reset mail (HLD §4.5, FR-ACC-05).
 *
 * Sets the new password and signs the user out on every device, at once: the refresh tokens are
 * revoked and the access tokens that are still out are refused from now on. It does not log in.
 */
final readonly class ResetPassword
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private PasswordPolicy $passwordPolicy,
        private PasswordHasher $hasher,
        private OneTimeTokens $oneTimeTokens,
        private UserRepository $users,
        private RefreshTokenRepository $refreshTokens,
        private FailedLogins $failedLogins,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $password Exactly as typed.
     *
     * @throws ValidationFailed When the password is not acceptable. The token stays usable.
     * @throws InvalidToken     When the token is unknown, expired (1 h) or already used.
     */
    public function execute(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): void
    {
        $this->passwordPolicy->assertAcceptable($password);

        $now = $this->clock->now();

        // Whose token it is, is read before the transaction, never inside it: MariaDB 11.8 gives a
        // transaction up (error 1020) when it locks a row that was changed after the transaction's
        // first plain read, which is what another confirm of the same link a moment earlier does.
        $owner = $this->oneTimeTokens->owner(TokenPurpose::ResetPassword, $token);
        if ($owner === null) {
            throw new InvalidToken();
        }

        $email = $this->transactions->run(function () use ($owner, $token, $password, $now): EmailAddress {
            // The account first, then the token: the order every use case locks in (a new reset
            // request holds the account and then replaces its token). Whether the token can still
            // be used is decided under these locks, by redeem().
            $user = $this->users->lock($owner);
            if ($user === null) {
                throw new InvalidToken();
            }
            $this->oneTimeTokens->redeem(TokenPurpose::ResetPassword, $token, $now);
            // The reset flow never activates an account (see RequestPasswordReset). Thrown inside
            // the transaction, this also leaves the token unused.
            if (!$user->isVerified()) {
                throw new InvalidToken();
            }

            // Hashed only now, with a valid token at hand: nobody without one can make us do the work.
            // The new token version ends the access tokens that are out, the revocation the sessions.
            $this->users->update($user->withPasswordHash($this->hasher->hash($password))->withNextTokenVersion());
            $this->refreshTokens->revokeAllOfUser($user->id, $now);

            return $user->email;
        });

        // After the commit, because counters are not part of any transaction. Failed logins that
        // were counted for the address over all clients were guesses at a password that no longer
        // exists, and they must not keep the owner, who has just proven to hold the mailbox, from
        // logging in.
        // The new password is committed and the link used up: a failure here must not reach the
        // caller, whose second try with the same link could only be refused. The failures then
        // stay counted until their window is over.
        try {
            $this->failedLogins->clearAddress($email);
        } catch (RuntimeException $failure) {
            // The class only: the message may name the address.
            $this->logger->warning('A rate limit counter could not be updated after the work was done.', ['exception' => $failure::class]);
        }
    }
}
