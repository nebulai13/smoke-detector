<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The personal invite code of each user (HLD §4.3 "invite_codes", §4.7 "Invite code",
 * SRS FR-FRND-01). One row per user, made at the first look at the code.
 *
 * - `code` is 10 characters out of 23456789ABCDEFGHJKLMNPQRSTUVWXYZ, stored in capitals. It is
 *   unique: a friend request starts from it, and no code belongs to two users. Compared as bytes
 *   (utf8mb4_bin), so the database does not treat other spellings as the same code.
 * - A new code replaces the row's code, and the old one leads nowhere at once.
 *
 * The row belongs to one user alone and goes with the account (ON DELETE CASCADE, in addition to
 * the eraser that account deletion runs).
 */
final class CreateInviteCodes extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `invite_codes` (
                `user_id` UUID NOT NULL,
                `code` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`user_id`),
                UNIQUE KEY `invite_codes_code` (`code`),
                CONSTRAINT `invite_codes_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
