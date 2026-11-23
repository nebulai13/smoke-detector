<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The sessions with their refresh tokens (HLD §4.3 "refresh_tokens", §4.5 "Login and tokens").
 *
 * One row per login, that is per device, however often its token is exchanged: a refresh replaces
 * the token in place, so the table grows with logins only (and those are bounded per user).
 *
 * - A refresh token is "<key>.<secret>". `key_hash` is the SHA-256 of the key, which stays the same
 *   for the whole session. `token_hash` is the SHA-256 of the whole current token. Both as 64
 *   hexadecimal characters. Neither the key nor the token is ever stored.
 * - A token whose key is known but whose hash is not the current one is an older token of the
 *   session shown again: the session is ended (`revoked_at`), unless it is the token that was
 *   replaced within the last ten seconds.
 * - `rotated_at` is the last refresh, `expires_at` 60 days after it (or after the login).
 * - `generation` counts the refreshes: 0 for the token of the login. The secret of a token follows
 *   from the key and this number under a key of the server, so the current token and the one
 *   before it can be made again when the key is presented, without storing either. That is how a
 *   refresh that is repeated within ten seconds is answered with the same new token.
 */
final class CreateRefreshTokens extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `refresh_tokens` (
                `id` UUID NOT NULL,
                `user_id` UUID NOT NULL,
                `key_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `token_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `device_label` VARCHAR(100) NULL,
                `created_at` DATETIME NOT NULL,
                `rotated_at` DATETIME NULL,
                `expires_at` DATETIME NOT NULL,
                `revoked_at` DATETIME NULL,
                `generation` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `refresh_tokens_key_hash` (`key_hash`),
                UNIQUE KEY `refresh_tokens_token_hash` (`token_hash`),
                KEY `refresh_tokens_user` (`user_id`),
                KEY `refresh_tokens_expires_at` (`expires_at`),
                CONSTRAINT `refresh_tokens_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
