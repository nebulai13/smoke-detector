<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The final results of the league periods (HLD §4.3 "league_results", §4.9, SRS FR-COMP-06 and
 * FR-COMP-08).
 *
 * - One row per user, period type (week / month / quarter / half_year) and period key (e.g.
 *   2026-W47), written by days:finalize when the last day of the period becomes final.
 * - The frozen baseline, the exact totals in tenths, smoke-free days, check-ins, counted days and
 *   `rules_version` as they were scored (NFR-04), never changed afterwards.
 * - `check_at` is when the cron step looks at the result next, to rank it. `rank`, `participants`,
 *   `xp` and `ranked_at` are NULL until the rank is decided. The index (ranked_at, check_at) finds
 *   the results that wait for their rank.
 *
 * The rows belong to one user alone and go with the account (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreateLeagueResults extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `league_results` (
                `user_id` UUID NOT NULL,
                `period_type` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `period_key` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `b0_tenths` SMALLINT UNSIGNED NOT NULL,
                `actual_tenths` INT UNSIGNED NOT NULL,
                `expected_tenths` INT UNSIGNED NOT NULL,
                `smoke_free_days` SMALLINT UNSIGNED NOT NULL,
                `check_ins` SMALLINT UNSIGNED NOT NULL,
                `counted_days` SMALLINT UNSIGNED NOT NULL,
                `rules_version` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `scored_at` DATETIME NOT NULL,
                `check_at` DATETIME NOT NULL,
                `rank` SMALLINT UNSIGNED NULL,
                `participants` SMALLINT UNSIGNED NULL,
                `xp` INT UNSIGNED NULL,
                `ranked_at` DATETIME NULL,
                PRIMARY KEY (`user_id`, `period_type`, `period_key`),
                KEY `league_results_ranked_at_check_at` (`ranked_at`, `check_at`),
                CONSTRAINT `league_results_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
