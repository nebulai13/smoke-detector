<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Every baseline a user ever had (HLD §4.3 "baseline_history", SRS FR-PLAN-02).
 *
 * Append-only: rows are inserted and never changed, because past values are never recalculated
 * (NFR-04). They are removed only together with the account.
 *
 * - `valid_from` is the user's local day from which the value counts. One value per user and day:
 *   the primary key is what makes "the baseline on day X" a single answer.
 * - `value` is sessions per day with one decimal place.
 * - `source` is onboarding, weekly, unchanged_low_data or capped.
 * - `w_mean`, `m_mean`, `w_days`, `m_days` are the inputs of the weekly update (FR-PLAN-04): the
 *   means over the checked-in days of the last 7 and 28 days, and how many days those were. They
 *   are NULL for the onboarding value, which has no inputs. DOUBLE keeps a mean exactly as it was
 *   calculated.
 * - `rules_version` names the rule table the value was calculated with.
 */
final class CreateBaselineHistory extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `baseline_history` (
                `user_id` UUID NOT NULL,
                `valid_from` DATE NOT NULL,
                `value` DECIMAL(4,1) NOT NULL,
                `source` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `w_mean` DOUBLE NULL,
                `m_mean` DOUBLE NULL,
                `w_days` TINYINT UNSIGNED NULL,
                `m_days` TINYINT UNSIGNED NULL,
                `rules_version` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                PRIMARY KEY (`user_id`, `valid_from`),
                CONSTRAINT `baseline_history_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
