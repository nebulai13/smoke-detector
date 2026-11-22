<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The local days of the users (HLD §4.3 "day_summaries", §4.6, SRS FR-LOG-04, FR-LOG-07).
 *
 * A row exists from the first log or check-in of a day; finalization creates the rows of the days
 * nobody touched.
 *
 * - `count` is the sum of the counts of the day's logs that are not deleted, kept in step by every
 *   sync (at most 300 sessions a day).
 * - `checked_in_at` is when the user checked the day in, NULL before.
 * - Everything after it is written by finalization and is NULL before: the limit the day was
 *   measured against, the XP it gave, its celebration events as JSON text (a list of
 *   {"type": …, "value": …}, the value only where the event has one), whether a freeze covered it,
 *   when it became final and under which rules. A day is final exactly when `finalized_at` is set;
 *   there is no status column that could contradict it.
 *
 * The rows belong to one user alone and go with the account (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreateDaySummaries extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `day_summaries` (
                `user_id` UUID NOT NULL,
                `local_date` DATE NOT NULL,
                `count` SMALLINT UNSIGNED NOT NULL,
                `checked_in_at` DATETIME NULL,
                `daily_limit` SMALLINT UNSIGNED NULL,
                `xp` INT UNSIGNED NULL,
                `events` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
                `freeze_used` BOOLEAN NULL,
                `finalized_at` DATETIME NULL,
                `rules_version` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
                PRIMARY KEY (`user_id`, `local_date`),
                CONSTRAINT `day_summaries_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
