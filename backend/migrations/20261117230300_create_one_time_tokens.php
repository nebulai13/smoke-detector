<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The tokens of mail links (HLD §4.3 "one_time_tokens"): purpose verify_email or reset_password.
 *
 * `token_hash` is the SHA-256 of the token as 64 hexadecimal characters. The token itself is only
 * in the mail.
 *
 * A user has one token per purpose (the unique key): only the link of the newest mail works. A new
 * token is written with INSERT … ON DUPLICATE KEY UPDATE, which replaces the older one in one step.
 * Deleting the old one first would lock the gap the new row goes into, also when there is nothing
 * to delete, and two requests of different users would then block each other (error 1213).
 *
 * `failed_attempts` counts the wrong passwords that were entered with a confirmation link (HLD §4.5
 * "Registration"): the fifth deletes the token. A new token starts at 0 again.
 */
final class CreateOneTimeTokens extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `one_time_tokens` (
                `id` UUID NOT NULL,
                `user_id` UUID NOT NULL,
                `purpose` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `token_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL,
                `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `one_time_tokens_token_hash` (`token_hash`),
                UNIQUE KEY `one_time_tokens_user_purpose` (`user_id`, `purpose`),
                KEY `one_time_tokens_expires_at` (`expires_at`),
                CONSTRAINT `one_time_tokens_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
