<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Who has blocked whom (HLD §4.3 "blocks", §4.7 "Blocks", SRS FR-FRND-02).
 *
 * - A table of its own and not a status of the friendship: a block has a direction, and each of two
 *   users can block the other. The primary key (blocker_id, blocked_id) makes blocking again change
 *   nothing; the index on `blocked_id` answers "has one of the two blocked the other?" from the
 *   other side.
 * - Nobody blocks themselves (CHECK constraint).
 *
 * A row belongs to both users and goes with either account (ON DELETE CASCADE on both columns, in
 * addition to the eraser that account deletion runs).
 */
final class CreateBlocks extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `blocks` (
                `blocker_id` UUID NOT NULL,
                `blocked_id` UUID NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`blocker_id`, `blocked_id`),
                KEY `blocks_blocked_id` (`blocked_id`),
                CONSTRAINT `blocks_blocker_fk` FOREIGN KEY (`blocker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `blocks_blocked_fk` FOREIGN KEY (`blocked_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `blocks_not_oneself` CHECK (`blocker_id` <> `blocked_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
