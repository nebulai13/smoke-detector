<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The reduction plan of a user (HLD §4.3 "plans", SRS FR-ACC-06): one row per user, written by the
 * onboarding and changed when the user changes goal, taper speed or default product.
 *
 * - `goal_mode` is "reduce" or "quit", and `target` the sessions per day the user wants to reach
 *   (0 exactly when the mode is "quit").
 * - `start_date` is the user's local day of the onboarding.
 * - `rules_version` names the rule table (backend/config/rules) in force when the plan was made.
 * - The baseline is not here: every value it ever had is in `baseline_history`.
 *
 * The row belongs to one user alone and goes with the account (ON DELETE CASCADE, in addition to
 * the eraser that account deletion runs).
 */
final class CreatePlans extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `plans` (
                `user_id` UUID NOT NULL,
                `goal_mode` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `target` SMALLINT UNSIGNED NOT NULL,
                `taper_speed` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `default_product` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `start_date` DATE NOT NULL,
                `rules_version` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                PRIMARY KEY (`user_id`),
                CONSTRAINT `plans_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
