<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The health milestones the users have reached for good (HLD §4.3 "user_milestones", §4.11, SRS
 * FR-GAME-09, FR-FRND-03): what friends may see, according to the user's own setting.
 *
 * - One row per user and milestone: a milestone is reached once, and the first time stays. A
 *   relapse takes nothing back. `milestone_key` is the value of the HealthMilestone enum, which the
 *   clients know.
 * - `reached_at` is the moment the span after the last session up to a final day was over.
 *
 * The rows belong to one user alone and go with the account (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreateUserMilestones extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `user_milestones` (
                `user_id` UUID NOT NULL,
                `milestone_key` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `reached_at` DATETIME NOT NULL,
                PRIMARY KEY (`user_id`, `milestone_key`),
                CONSTRAINT `user_milestones_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
