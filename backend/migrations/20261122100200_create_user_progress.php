<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * What each user has built up over the days that are final (HLD §4.3 "user_progress", §4.6,
 * SRS FR-GAME-01 to FR-GAME-04). One row per user, created with the plan.
 *
 * - The streak counters and `freezes_held` are the domain's StreakState, `xp_total` the sum of the
 *   user's `xp_events`, `level` the level that total gives.
 * - `next_day` is the next local date to finalize: every earlier day of the user is final.
 * - `finalize_due_at` (when `next_day` becomes final, 48 hours after it ended) and `baseline_due_at`
 *   (the start of the user's next Monday) are UTC instants. The cron job asks every minute who is
 *   due; each has an index, so it reads those users and nobody else.
 *
 * The row belongs to one user alone and goes with the account (ON DELETE CASCADE, in addition to
 * the eraser that account deletion runs).
 */
final class CreateUserProgress extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `user_progress` (
                `user_id` UUID NOT NULL,
                `check_in_current` SMALLINT UNSIGNED NOT NULL,
                `check_in_longest` SMALLINT UNSIGNED NOT NULL,
                `under_limit_current` SMALLINT UNSIGNED NOT NULL,
                `under_limit_longest` SMALLINT UNSIGNED NOT NULL,
                `freezes_held` TINYINT UNSIGNED NOT NULL,
                `xp_total` BIGINT UNSIGNED NOT NULL,
                `level` SMALLINT UNSIGNED NOT NULL,
                `next_day` DATE NOT NULL,
                `finalize_due_at` DATETIME NOT NULL,
                `baseline_due_at` DATETIME NOT NULL,
                PRIMARY KEY (`user_id`),
                KEY `user_progress_finalize_due_at` (`finalize_due_at`),
                KEY `user_progress_baseline_due_at` (`baseline_due_at`),
                CONSTRAINT `user_progress_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
