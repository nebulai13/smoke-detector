<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The entries of the activity feed (HLD §4.3 "feed_events", §4.12, SRS FR-SOC-01).
 *
 * - `position` is the order of writing: the feed is shown by it, newest first, and it is never sent.
 *   The times cannot give the order: several steps of one cron run have the same time.
 * - `id` is random (UUID version 4), so what a client gets to name an entry says nothing about when
 *   it was written.
 * - `type` is the value of the FeedEventType enum, `detail` the level, the badge or the milestone.
 * - `other_user_id` is the opponent of a duel. It is emptied when that account goes (ON DELETE SET
 *   NULL, under the eraser that does the same); the entry stays with its user.
 * - (`user_id`, `position`) finds the newest entries of the user and the friends.
 *
 * The rows belong to their user alone and go with the account (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreateFeedEvents extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `feed_events` (
                `position` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id` UUID NOT NULL,
                `user_id` UUID NOT NULL,
                `type` VARCHAR(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `detail` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
                `other_user_id` UUID NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`position`),
                UNIQUE KEY `feed_events_id` (`id`),
                KEY `feed_events_user_position` (`user_id`, `position`),
                KEY `feed_events_other_user` (`other_user_id`),
                CONSTRAINT `feed_events_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `feed_events_other_user_fk` FOREIGN KEY (`other_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
