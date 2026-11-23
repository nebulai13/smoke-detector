<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Friend requests and friendships (HLD §4.3 "friendships", §4.7 "Requests", SRS FR-FRND-01 to
 * FR-FRND-04). One row per pair of users, whoever asked.
 *
 * - `user_a` and `user_b` are the two users, the one with the smaller id first. "Smaller" is decided
 *   by the code, comparing the ids as text: MariaDB orders its UUID type differently, so no SQL here
 *   compares the two. The primary key makes the pair one row, and the index on `user_b` finds the
 *   links of a user from the other column.
 * - `requested_by` is the one of the two who asked, `requested_at` when. `accepted_at` is NULL while
 *   the request waits for its answer. There is no status column that could contradict it. A
 *   declined or withdrawn request and an ended friendship are deleted.
 * - Nobody is their own friend, and the one who asked is one of the two (CHECK constraints).
 *
 * A row belongs to both users and goes with either account (ON DELETE CASCADE on both columns, in
 * addition to the eraser that account deletion runs).
 */
final class CreateFriendships extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `friendships` (
                `user_a` UUID NOT NULL,
                `user_b` UUID NOT NULL,
                `requested_by` UUID NOT NULL,
                `requested_at` DATETIME NOT NULL,
                `accepted_at` DATETIME NULL,
                PRIMARY KEY (`user_a`, `user_b`),
                KEY `friendships_user_b` (`user_b`),
                CONSTRAINT `friendships_user_a_fk` FOREIGN KEY (`user_a`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `friendships_user_b_fk` FOREIGN KEY (`user_b`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `friendships_two_users` CHECK (`user_a` <> `user_b`),
                CONSTRAINT `friendships_requested_by_one_of_them` CHECK (`requested_by` = `user_a` OR `requested_by` = `user_b`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
