<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The mails that wait to be sent (HLD §4.3 "mail_outbox", §4.5 "Mail"). A web request never talks
 * to the mail server: it stores its mail here, in the transaction that stores the token of the
 * mail's link, and the cron job sends it (`mail:send`) and deletes the row.
 *
 * `sealed_values` holds the links of the mail, encrypted (SodiumMailValuesCipher): a confirmation
 * or reset link carries its token in plain text, and `one_time_tokens` deliberately stores only
 * the hash. `recipient` is the address of the account at the moment the mail was queued.
 *
 * `not_before` is when the sender may take the row next: at once for a new mail, later after a
 * failed try, and ten minutes ahead while a run is sending it. `attempts` counts the failed tries.
 *
 * Every mail is about one account (`user_id`) and is deleted with it.
 *
 * Deliberately no index on `not_before`. The table holds the mails of the last minutes, so reading
 * it whole costs nothing. With an index, taking mails (SELECT … FOR UPDATE SKIP LOCKED, then
 * moving `not_before`) would move index entries into gaps that a second run has locked at the
 * same moment, and the two runs could end in a deadlock.
 */
final class CreateMailOutbox extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `mail_outbox` (
                `id` UUID NOT NULL,
                `user_id` UUID NOT NULL,
                `template` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `locale` VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `recipient` VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `sealed_values` VARCHAR(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `created_at` DATETIME NOT NULL,
                `not_before` DATETIME NOT NULL,
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `mail_outbox_user` (`user_id`),
                CONSTRAINT `mail_outbox_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
