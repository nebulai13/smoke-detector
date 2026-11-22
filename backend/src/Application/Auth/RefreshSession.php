<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use Psr\Clock\ClockInterface;
use SensitiveParameter;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /auth/refresh: exchanges a refresh token for a new token pair (HLD §4.5 "Login and tokens").
 *
 * A refresh replaces the refresh token. Its successor takes its place in the same session. When a token of
 * the session shows up that is not the current one, two parties hold tokens of one session, and
 * one of them is not the user: the session is ended and the user has to log in again.
 *
 * One exception (SRS OQ-12): for ten seconds after a refresh, the token that was just replaced is
 * answered like a successful refresh that carries the SAME new refresh token as the first answer,
 * and nothing is rotated again. Two browser tabs that refresh together, and a client that repeats
 * a refresh whose answer was lost, therefore end up with the identical current token, whichever
 * answer arrives last. The price: somebody who stole that one token and uses it within those ten
 * seconds gets the current token too. That is noticed at the next refresh but one, when one of the
 * two presents a token that is older than the tolerance allows.
 */
final readonly class RefreshSession
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private RefreshTokenRepository $refreshTokens,
        private UserRepository $users,
        private Sessions $sessions,
    ) {
    }

    /**
     * @throws InvalidRefreshToken When the token is unknown, expired, revoked, or an older token of
     *                             its session (other than the one replaced within the last ten seconds).
     */
    public function execute(#[SensitiveParameter] string $refreshToken): Session
    {
        $now = $this->clock->now();

        // Read before the transaction, not inside it: this read only tells which account to lock.
        // Inside, it would fix what the transaction may see, and the database gives a transaction
        // up (error 1020, "record has changed since last read") when it then locks a row that
        // another request changed in the meantime. That other request is the second browser tab
        // refreshing with the same token, the very case the tolerance below is for.
        $found = $this->sessions->find($refreshToken);
        if ($found === null) {
            throw new InvalidRefreshToken();
        }

        // A refusal is returned as null and thrown after the commit: ending the session is part
        // of refusing, and must not be rolled back with it.
        $session = $this->transactions->run(function () use ($found, $refreshToken, $now): ?Session {
            // The account first, then the session: the order every use case locks in (account
            // deletion and password reset hold the account and then touch its sessions). The
            // session is read again under the lock, because what was found before may be history.
            $user = $this->users->lock($found->userId);
            $stored = $user === null ? null : $this->refreshTokens->lock($found->id);
            if ($user === null || $stored === null || $stored->revokedAt !== null) {
                return null;
            }
            $current = $this->sessions->isCurrent($stored, $refreshToken);
            if (!$current && !$this->sessions->wasJustReplaced($stored, $refreshToken, $now)) {
                $this->refreshTokens->revoke($stored->id, $now);

                return null;
            }
            if ($stored->expiresAt <= $now || !$user->isVerified()) {
                return null;
            }

            return $current
                ? $this->sessions->rotate($user, $stored, $refreshToken, $now)
                : $this->sessions->repeat($user, $stored, $refreshToken);
        });

        return $session ?? throw new InvalidRefreshToken();
    }
}
