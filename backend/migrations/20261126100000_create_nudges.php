<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The nudges between friends (HLD §4.3 "nudges", §4.14, SRS FR-SOC-03).
 *
 * - (`from_user_id`, `to_user_id`, `day`) is the primary key: one nudge per friend and day of the
 *   sender. `day` is the local date of the sender in the zone of the account.
 * - `delivered` is whether the receiver had not checked in that day. Every nudge is stored, also one
 *   that was not delivered: the limit of one per day needs it. It is never told to the sender.
 * - (`to_user_id`, `created_at`) finds the nudges of today to a user.
 *
 * A row goes with the account of its sender and with that of its receiver (ON DELETE CASCADE, in
 * addition to the eraser that account deletion runs).
 */
final class CreateNudges extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `nudges` (
                `from_user_id` UUID NOT NULL,
                `to_user_id` UUID NOT NULL,
                `day` DATE NOT NULL,
                `created_at` DATETIME NOT NULL,
                `delivered` TINYINT(1) NOT NULL,
                PRIMARY KEY (`from_user_id`, `to_user_id`, `day`),
                KEY `nudges_to_created` (`to_user_id`, `created_at`),
                CONSTRAINT `nudges_from_fk` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `nudges_to_fk` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
