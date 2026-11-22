<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use Psr\Clock\ClockInterface;
use SensitiveParameter;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /auth/logout: ends the session the refresh token belongs to, on this device only
 * (HLD §4.5, FR-ACC-04). Sessions on other devices are other rows and stay.
 *
 * An unknown token is not an error: the caller wanted to be logged out, and is.
 */
final readonly class LogOut
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private RefreshTokenRepository $refreshTokens,
        private UserRepository $users,
        private Sessions $sessions,
    ) {
    }

    public function execute(#[SensitiveParameter] string $refreshToken): void
    {
        $now = $this->clock->now();

        // Any token of the session will do, also an older one: whoever holds it wants the
        // session to be over, and so does the user if that is somebody else.
        // Read before the transaction, not inside it (see RefreshSession): a logout that waits for
        // a refresh of the same session would otherwise be given up by the database (error 1020)
        // and leave the session open.
        $found = $this->sessions->find($refreshToken);
        if ($found === null) {
            return;
        }

        $this->transactions->run(function () use ($found, $now): void {
            // The account first, then the session: the order every use case locks in.
            if ($this->users->lock($found->userId) !== null && $this->refreshTokens->lock($found->id) !== null) {
                $this->refreshTokens->revoke($found->id, $now);
            }
        });
    }
}
