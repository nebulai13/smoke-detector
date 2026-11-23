<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The time zones of each user and from which local day each counts (HLD §4.3 "timezone_history",
 * §4.6 "Local days", SRS FR-I18N-02).
 *
 * The days of a user are counted in these zones, so an instant keeps its day after the user moves.
 *
 * - The history starts with the onboarding. After it, a change made on local day D counts from
 *   D + 1: today keeps its zone. A second change on the same day replaces the entry for tomorrow.
 *   The primary key (user_id, valid_from) makes "the zone on day X" a single answer.
 * - `timezone` is an IANA name. `users.timezone` keeps what the user chose last, which may already
 *   be the zone of tomorrow.
 *
 * The rows belong to one user alone and go with the account (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreateTimezoneHistory extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `timezone_history` (
                `user_id` UUID NOT NULL,
                `valid_from` DATE NOT NULL,
                `timezone` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                PRIMARY KEY (`user_id`, `valid_from`),
                CONSTRAINT `timezone_history_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
