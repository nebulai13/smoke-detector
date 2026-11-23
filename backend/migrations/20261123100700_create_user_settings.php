<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The settings of the users (HLD §4.3 "user_settings", §4.11, SRS FR-GAME-09).
 *
 * - One row per user, made by the first choice: no row means the defaults.
 * - `milestone_sharing` is the value of the MilestoneSharing enum: what friends see of the user's
 *   health milestones. The notification settings of step 7 will join it.
 *
 * The row belongs to one user alone and goes with the account (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreateUserSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `user_settings` (
                `user_id` UUID NOT NULL,
                `milestone_sharing` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                PRIMARY KEY (`user_id`),
                CONSTRAINT `user_settings_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
