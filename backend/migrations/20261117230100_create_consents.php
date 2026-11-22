<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The consent records (HLD §4.3 "consents", SRS FR-ACC-02, LEG-03): which user agreed to which text
 * (age18, art9, privacy) in which version, and when.
 *
 * The version is part of the key, so a later consent to a new version of a text is a new row next
 * to the old one. The rows go when the account goes (withdrawal = account deletion, LEG-03).
 */
final class CreateConsents extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `consents` (
                `user_id` UUID NOT NULL,
                `type` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `version` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `granted_at` DATETIME NOT NULL,
                PRIMARY KEY (`user_id`, `type`, `version`),
                CONSTRAINT `consents_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
