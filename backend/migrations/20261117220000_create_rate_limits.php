<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Counters for rate limiting (HLD §4.3, §4.5 "Rate limits", NFR-05).
 *
 * One row per key and fixed time window. A hit is counted with
 *   INSERT … VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1
 * and old windows are deleted by the cron job (HLD §5.2).
 *
 * Rules for every migration (checked by MigrationConventionsTest and SchemaConventionsTest):
 * - The deploy runs migrations BEFORE the new release goes live (HLD §8), while the previous release
 *   still serves requests. So a migration only adds: new tables, new nullable or defaulted columns,
 *   new indexes. Nothing is dropped, renamed or changed in place, and there is no down().
 * - Plain SQL, one statement per execute() (the connection refuses several statements in one call).
 * - CREATE TABLE IF NOT EXISTS, so a migration that stopped half-way can be run again.
 * - Every table declares ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci: the
 *   server defaults are not to be trusted (HLD §11).
 * - Times are DATETIME columns holding UTC.
 */
final class CreateRateLimits extends AbstractMigration
{
    public function up(): void
    {
        // `key` is compared byte by byte (utf8mb4_bin), so two different keys never share a counter.
        // The limiter decides what a key is. It must not be raw personal data: emails and IPs go in as
        // hashes. A hash without a secret can still be tested against a known address, so the rows
        // remain personal data and are short-lived (the cleanup deletes old windows).
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `rate_limits` (
                `key` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `window_start` DATETIME NOT NULL,
                `hits` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`key`, `window_start`),
                KEY `rate_limits_window_start` (`window_start`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
