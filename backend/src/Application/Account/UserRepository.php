<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use DateTimeImmutable;

/**
 * The accounts (HLD §4.3 "users").
 */
interface UserRepository
{
    public function find(UserId $id): ?User;

    public function findByEmail(EmailAddress $email): ?User;

    /**
     * Reads the account and keeps it locked until the transaction ends, so that what the use case
     * decides from it is still true when it writes. Only inside TransactionRunner::run().
     */
    public function lock(UserId $id): ?User;

    /**
     * @throws EmailAlreadyRegistered When the email address belongs to another account.
     */
    public function add(User $user): void;

    /**
     * Writes every value of the account back. The caller holds the lock() on it.
     */
    public function update(User $user): void;

    /**
     * Deletes the account itself, as the last step of account deletion (FR-ACC-08): everything else
     * the user has was erased before, in the same transaction. Deleting an account that does not
     * exist does nothing.
     */
    public function delete(UserId $id): void;

    /**
     * Deletes the registrations that were submitted before $before and never confirmed (FR-ACC-03),
     * together with everything that belongs to them: consents and tokens.
     *
     * @return int How many accounts were deleted.
     */
    public function deleteUnverifiedCreatedBefore(DateTimeImmutable $before): int;
}
