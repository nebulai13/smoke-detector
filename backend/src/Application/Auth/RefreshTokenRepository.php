<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateTimeImmutable;
use SensitiveParameter;
use SmokeDetector\Application\Account\UserId;

/**
 * The sessions with their refresh tokens (HLD §4.3 "refresh_tokens"): one row per login.
 */
interface RefreshTokenRepository
{
    public function add(RefreshToken $session): void;

    /**
     * The session whose tokens carry the key with this hash. A plain read, without a lock: it
     * tells which account to lock first.
     */
    public function findByKeyHash(#[SensitiveParameter] string $keyHash): ?RefreshToken;

    /**
     * Reads the session and keeps it locked until the transaction ends, so that two requests with
     * the same token cannot both find it current. Only inside a transaction, and only after the
     * account was locked (UserRepository::lock()): that is the order every use case keeps.
     */
    public function lock(string $id): ?RefreshToken;

    /**
     * Replaces the token of the session by its successor, in place.
     *
     * @param int $generation The generation of the successor: one more than the session had.
     */
    public function rotate(string $id, #[SensitiveParameter] string $tokenHash, int $generation, DateTimeImmutable $at, DateTimeImmutable $expiresAt): void;

    /**
     * Ends the session, unless it is ended already: the first time stays.
     */
    public function revoke(string $id, DateTimeImmutable $at): void;

    /**
     * Ends every session of the user that is not ended yet: all devices are signed out.
     */
    public function revokeAllOfUser(UserId $userId, DateTimeImmutable $at): void;

    /**
     * Deletes the sessions of the user that are not revoked, except the $keep of them that were
     * used last (logged in or refreshed; of two at the same second the newer login stays).
     *
     * Revoked sessions (logged out, replaced at a password reset) neither count nor are deleted
     * here: otherwise logging out and in again on one device would end the session of another.
     * The cleanup deletes them (deleteExpiredAndRevoked()).
     *
     * @return int How many were deleted.
     */
    public function deleteAllButLastUsedOfUser(UserId $userId, int $keep): int;

    /**
     * Deletes the sessions that can never be used again: expired at $now, or revoked.
     *
     * @return int How many were deleted.
     */
    public function deleteExpiredAndRevoked(DateTimeImmutable $now): int;
}
