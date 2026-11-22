<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * What each user's plan said from which local day on (HLD §4.3 "plan_history", §4.6 "Plan and
 * limit", SRS NFR-04).
 *
 * The limit of a day is calculated with the terms valid on it, so every past day can be
 * recalculated exactly and nobody can raise today's limit after going over it.
 *
 * - The history starts with the onboarding. A change counts from the next local day; a second
 *   change on the same day replaces the entry for tomorrow. An entry that is in force is never
 *   changed. The primary key (user_id, valid_from) is what makes "the plan on day X" a single answer.
 * - `goal_mode`, `target` and `taper_speed` mean what they mean in `plans`, which keeps what the
 *   user chose last.
 * - `rules_version` names the rule table the plan was made under.
 *
 * The rows belong to one user alone and go with the account (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreatePlanHistory extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `plan_history` (
                `user_id` UUID NOT NULL,
                `valid_from` DATE NOT NULL,
                `goal_mode` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `target` SMALLINT UNSIGNED NOT NULL,
                `taper_speed` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `rules_version` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                PRIMARY KEY (`user_id`, `valid_from`),
                CONSTRAINT `plan_history_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
