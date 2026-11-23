<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateInterval;
use DateTimeImmutable;
use SensitiveParameter;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\IdGenerator;

/**
 * Issues and redeems the tokens of mail links. Both happen inside the calling use case's transaction.
 */
final readonly class OneTimeTokens
{
    /**
     * How many wrong passwords a confirmation link survives (see VerifyEmail). The fifth deletes it:
     * a link is 256 random bits, but the password that goes with it is whatever somebody chose.
     */
    public const int MAX_FAILED_ATTEMPTS = 5;

    public function __construct(
        private OneTimeTokenRepository $tokens,
        private TokenGenerator $generator,
        private IdGenerator $ids,
    ) {
    }

    /**
     * Makes a new token and withdraws the user's earlier one of the same purpose: only the link of
     * the latest mail works (HLD §4.5 "invalidate older links"). The caller holds the lock on the
     * account.
     *
     * @return string The token, for the mail. It is stored as a hash and cannot be read again.
     */
    public function issue(UserId $userId, TokenPurpose $purpose, DateTimeImmutable $now): string
    {
        $token = $this->generator->generate();
        $this->tokens->put(new OneTimeToken(
            $this->ids->next(),
            $userId,
            $purpose,
            TokenHash::of($token),
            $now->add(new DateInterval('PT' . $purpose->lifetimeSeconds() . 'S')),
            null,
        ));

        return $token;
    }

    /**
     * Whose token this is, used or not, expired or not. A plain read: the use case locks that
     * account and only then redeems the token, the order every use case takes its locks in.
     */
    public function owner(TokenPurpose $purpose, #[SensitiveParameter] string $token): ?UserId
    {
        return $this->tokens->findByHash($purpose, TokenHash::of($token))?->userId;
    }

    /**
     * Whose token this is, when it can still be used at $now: not used, not expired, and with
     * attempts left. A plain read, like owner(): it costs one lookup by an index, so a token that
     * is worth nothing is turned away before anything expensive happens.
     */
    public function ownerOfUsable(TokenPurpose $purpose, #[SensitiveParameter] string $token, DateTimeImmutable $now): ?UserId
    {
        $stored = $this->tokens->findByHash($purpose, TokenHash::of($token));

        return $stored !== null && self::hasAttemptsLeft($stored, $now) ? $stored->userId : null;
    }

    /**
     * Counts one attempt to use the token with a password, before the password is looked at: attempts
     * that arrive at the same moment queue up at the token and cannot outrun the count. The caller
     * holds the lock on the account of ownerOfUsable(). A successful attempt takes nothing back:
     * it uses the token up (redeem()).
     *
     * @return bool Whether this was the last attempt the token has: if it fails, withdraw() it.
     *
     * @throws InvalidToken When there is no such token for this purpose, or it is expired, used or
     *                      out of attempts.
     */
    public function countAttempt(TokenPurpose $purpose, #[SensitiveParameter] string $token, DateTimeImmutable $now): bool
    {
        $stored = $this->tokens->lockByHash($purpose, TokenHash::of($token));
        if ($stored === null || !self::hasAttemptsLeft($stored, $now)) {
            throw new InvalidToken();
        }

        $this->tokens->addFailedAttempt($stored->id);

        return $stored->failedAttempts + 1 >= self::MAX_FAILED_ATTEMPTS;
    }

    /**
     * Deletes the token, if it is still there: its link stops working. The caller holds the lock
     * on the account. Found by its hash and not by the id of its row, which a newer token of the
     * user may have taken over in the meantime (see OneTimeTokenRepository::put()).
     */
    public function withdraw(TokenPurpose $purpose, #[SensitiveParameter] string $token): void
    {
        $stored = $this->tokens->lockByHash($purpose, TokenHash::of($token));
        if ($stored !== null) {
            $this->tokens->delete($stored->id);
        }
    }

    /**
     * Uses the token up. The caller holds the lock on the account of owner().
     *
     * @return UserId Whose token it was.
     *
     * @throws InvalidToken When there is no such token for this purpose, or it is expired or used.
     */
    public function redeem(TokenPurpose $purpose, #[SensitiveParameter] string $token, DateTimeImmutable $now): UserId
    {
        $stored = $this->tokens->lockByHash($purpose, TokenHash::of($token));
        if ($stored === null || !self::isLive($stored, $now)) {
            throw new InvalidToken();
        }

        $this->tokens->markUsed($stored->id, $now);

        return $stored->userId;
    }

    private static function isLive(OneTimeToken $token, DateTimeImmutable $now): bool
    {
        return $token->usedAt === null && $token->expiresAt > $now;
    }

    private static function hasAttemptsLeft(OneTimeToken $token, DateTimeImmutable $now): bool
    {
        return self::isLive($token, $now) && $token->failedAttempts < self::MAX_FAILED_ATTEMPTS;
    }
}
