<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * The tokens of mail links (HLD §4.3 "one_time_tokens").
 */
interface OneTimeTokenRepository
{
    /**
     * Stores the token as the only one the user has for its purpose: an earlier one, used or not,
     * is replaced, so its link stops working. One step for the database, which neither looks for
     * the earlier token first nor deletes it: that is what keeps two requests of different users
     * from locking each other out. A replaced token leaves its id to its successor: the id of
     * $token only counts when the user had no token of this purpose. The failed attempts of the
     * earlier token do not carry over: the new one is stored as given.
     */
    public function put(OneTimeToken $token): void;

    /**
     * The token with this hash and purpose. A plain read, without a lock: it tells which account
     * to lock first.
     */
    public function findByHash(TokenPurpose $purpose, #[SensitiveParameter] string $tokenHash): ?OneTimeToken;

    /**
     * Reads the token with this hash and purpose and keeps it locked until the transaction ends, so
     * that two requests with the same token cannot both find it unused. Only inside a transaction,
     * and only after the account was locked (UserRepository::lock()).
     */
    public function lockByHash(TokenPurpose $purpose, #[SensitiveParameter] string $tokenHash): ?OneTimeToken;

    public function markUsed(string $id, DateTimeImmutable $at): void;

    /**
     * Counts one more failed attempt on the token. The caller holds the token (lockByHash()).
     */
    public function addFailedAttempt(string $id): void;

    /**
     * Deletes the token: its link stops working. The caller holds the token (lockByHash()).
     */
    public function delete(string $id): void;

    /**
     * Deletes the tokens that can no longer be used: expired at $now, or used.
     *
     * @return int How many were deleted.
     */
    public function deleteExpiredAndUsed(DateTimeImmutable $now): int;
}
