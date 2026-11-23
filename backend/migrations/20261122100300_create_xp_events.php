<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The XP ledger (HLD §4.3 "xp_events", SRS FR-GAME-01): every award of XP, written at finalization.
 *
 * Append-only: rows are inserted and never changed. `user_progress.xp_total` is the sum of a user's
 * rows, kept there for speed.
 *
 * - `id` is a UUIDv7.
 * - `local_date` is the user's day the XP was earned on, `source` what for (the domain's XpSource),
 *   `amount` how much.
 * - `detail` is the streak length of a milestone, NULL otherwise.
 * - The index (user_id, local_date) reads a user's ledger by day.
 *
 * The rows belong to one user alone and go with the account (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreateXpEvents extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `xp_events` (
                `id` UUID NOT NULL,
                `user_id` UUID NOT NULL,
                `local_date` DATE NOT NULL,
                `source` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `amount` INT UNSIGNED NOT NULL,
                `detail` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `xp_events_user_date` (`user_id`, `local_date`),
                CONSTRAINT `xp_events_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
