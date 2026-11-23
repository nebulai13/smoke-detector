<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The logs of the users (HLD §4.3 "smoke_logs", §4.6, SRS FR-LOG-02, FR-LOG-03).
 *
 * - `id` is the UUID the app gave the log. Syncing stores by it with INSERT … ON DUPLICATE KEY
 *   UPDATE, so the same log sent twice is one row. A log never changes its owner.
 * - `smoked_at` is the instant in UTC. `local_date` is the user's day the log counts for, worked out
 *   by the server from the time zone history; the client's idea of the date is never trusted.
 * - `count` is 1 to 50 sessions.
 * - `created_at` is when the server first stored the log and never changes; `updated_at` the last
 *   change. `deleted_at` marks a deleted log: it stays stored and stays deleted, so a late copy from
 *   another device cannot bring it back.
 * - The index (user_id, local_date) is what every sync sums the sessions of a day by.
 *
 * The rows belong to one user alone and go with the account (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreateSmokeLogs extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `smoke_logs` (
                `id` UUID NOT NULL,
                `user_id` UUID NOT NULL,
                `smoked_at` DATETIME NOT NULL,
                `local_date` DATE NOT NULL,
                `product_type` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `count` TINYINT UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                `deleted_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `smoke_logs_user_date` (`user_id`, `local_date`),
                CONSTRAINT `smoke_logs_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
