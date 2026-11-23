<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Duels between two friends (HLD §4.3 "duels", §4.8, SRS FR-COMP-01 to FR-COMP-05, FR-COMP-08).
 *
 * - `challenger_id` proposed the duel, `opponent_id` was challenged. `challenger_start` and
 *   `opponent_start` are the first day of the duel in the calendar of each of the two (NULL until
 *   it is accepted).
 * - What the decision writes is NULL before it: `decided_at`, `winner`, per side the frozen baseline,
 *   the exact totals in tenths, smoke-free days, check-ins and XP, and `rules_version` (NFR-04).
 *   Columns and not JSON, so that the side of a deleted user can be emptied by name.
 * - Indexes: the duels of a user from either side, and the proposals whose time is over (by status,
 *   which also finds the running duels to decide).
 *
 * A duel outlives an account: account deletion runs the eraser (DuelsOfUser), which deletes the
 * user's proposals, turns the running duels into forfeits and empties the user's side. The foreign
 * keys (ON DELETE SET NULL) are the safety net under it. MariaDB allows no CHECK constraint on a
 * column with such an action, so that the two ids differ is left to the code (a friend is never
 * oneself).
 */
final class CreateDuels extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `duels` (
                `id` UUID NOT NULL,
                `challenger_id` UUID NULL,
                `opponent_id` UUID NULL,
                `period_days` SMALLINT UNSIGNED NOT NULL,
                `status` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `created_at` DATETIME NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `answered_at` DATETIME NULL,
                `challenger_start` DATE NULL,
                `opponent_start` DATE NULL,
                `decided_at` DATETIME NULL,
                `winner` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
                `challenger_b0_tenths` SMALLINT UNSIGNED NULL,
                `challenger_actual_tenths` INT UNSIGNED NULL,
                `challenger_expected_tenths` INT UNSIGNED NULL,
                `challenger_smoke_free_days` SMALLINT UNSIGNED NULL,
                `challenger_check_ins` SMALLINT UNSIGNED NULL,
                `challenger_xp` INT UNSIGNED NULL,
                `opponent_b0_tenths` SMALLINT UNSIGNED NULL,
                `opponent_actual_tenths` INT UNSIGNED NULL,
                `opponent_expected_tenths` INT UNSIGNED NULL,
                `opponent_smoke_free_days` SMALLINT UNSIGNED NULL,
                `opponent_check_ins` SMALLINT UNSIGNED NULL,
                `opponent_xp` INT UNSIGNED NULL,
                `rules_version` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
                PRIMARY KEY (`id`),
                KEY `duels_challenger_id` (`challenger_id`),
                KEY `duels_opponent_id` (`opponent_id`),
                KEY `duels_status_expires_at` (`status`, `expires_at`),
                CONSTRAINT `duels_challenger_fk` FOREIGN KEY (`challenger_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                CONSTRAINT `duels_opponent_fk` FOREIGN KEY (`opponent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
