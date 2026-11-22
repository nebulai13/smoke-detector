<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The accounts (HLD §4.3 "users", §4.5).
 *
 * - `id` is a UUID version 7 in a native UUID column (16 bytes, time-ordered, so new rows go to the
 *   end of the index).
 * - `email` is stored lower-cased and compared byte by byte (utf8mb4_bin). The table default
 *   utf8mb4_unicode_ci would treat "é" and "e" as equal, and two different mailboxes as one account.
 * - `password_hash` is the output of password_hash() (Argon2id). 255 characters leave room for
 *   other parameters and algorithms.
 * - `token_version` counts the password resets. An access token is accepted only with the number
 *   the account has now, so a reset ends the access tokens that are out (FR-ACC-05).
 * - `email_verified_at` is NULL while the registration is not confirmed. The cleanup of old
 *   unconfirmed registrations reads the index on (email_verified_at, created_at).
 */
final class CreateUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `users` (
                `id` UUID NOT NULL,
                `email` VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `password_hash` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `display_name` VARCHAR(30) NOT NULL,
                `avatar_key` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `locale` VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `timezone` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `email_verified_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `token_version` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `users_email` (`email`),
                KEY `users_unverified` (`email_verified_at`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
