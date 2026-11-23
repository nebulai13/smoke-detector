<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The badges of the users (HLD §4.3 "user_badges", §4.10, SRS FR-GAME-08).
 *
 * - One row per user and badge: a user holds a badge once, and the first award stays. `badge_key` is
 *   the value of the Badge enum, which the clients know.
 * - `awarded_at` is when the day, the duel or the rank that justified it became final.
 *
 * The rows belong to one user alone and go with the account (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreateUserBadges extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `user_badges` (
                `user_id` UUID NOT NULL,
                `badge_key` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `awarded_at` DATETIME NOT NULL,
                PRIMARY KEY (`user_id`, `badge_key`),
                CONSTRAINT `user_badges_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
