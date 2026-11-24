<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The reactions to the entries of the activity feed (HLD §4.3 "feed_reactions", §4.13, SRS FR-SOC-02).
 *
 * - (`event_id`, `user_id`) is the primary key: a user has one reaction to an entry. It also finds
 *   the reactions to the entries of a page of the feed.
 * - `reaction` is the value of the ReactionKey enum. It can change; `created_at` is the first time
 *   and stays.
 * - (`user_id`, `created_at`) finds what a user sent: counted for a badge and exported.
 *
 * A row goes with its entry and with the user who reacted (ON DELETE CASCADE, in addition to the
 * eraser that account deletion runs).
 */
final class CreateFeedReactions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `feed_reactions` (
                `event_id` UUID NOT NULL,
                `user_id` UUID NOT NULL,
                `reaction` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`event_id`, `user_id`),
                KEY `feed_reactions_user_created` (`user_id`, `created_at`),
                CONSTRAINT `feed_reactions_event_fk` FOREIGN KEY (`event_id`) REFERENCES `feed_events` (`id`) ON DELETE CASCADE,
                CONSTRAINT `feed_reactions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
