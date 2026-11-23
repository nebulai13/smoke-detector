<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * Rules every table must follow, checked against the migrated schema, so a later migration cannot
 * forget them.
 *
 * The test schema's own default is latin1 (TestDatabase), like a hosting server with unsafe defaults:
 * a table that does not declare its character set fails here.
 */
final class SchemaConventionsTest extends IntegrationTestCase
{
    public function test_every_table_is_innodb_with_utf8mb4_unicode_ci(): void
    {
        $tables = $this->sql()->rows(
            'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_COLLATION AS collation '
            . "FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' "
            . 'ORDER BY TABLE_NAME',
        );

        self::assertNotSame([], $tables);
        foreach ($tables as $table) {
            self::assertIsString($table['name']);
            // InnoDB: transactions and row locks. MyISAM has neither, and a use case's rollback
            // would silently keep its rows.
            self::assertSame('InnoDB', $table['engine'], "Table {$table['name']} must be InnoDB.");
            self::assertSame(
                'utf8mb4_unicode_ci',
                $table['collation'],
                "Table {$table['name']} must declare utf8mb4 / utf8mb4_unicode_ci.",
            );
        }
    }

    public function test_every_text_column_is_utf8mb4(): void
    {
        $columns = $this->sql()->rows(
            'SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND CHARACTER_SET_NAME IS NOT NULL '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION',
        );

        self::assertNotSame([], $columns);
        foreach ($columns as $column) {
            self::assertIsString($column['tbl']);
            self::assertIsString($column['col']);
            $name = "{$column['tbl']}.{$column['col']}";
            self::assertSame('utf8mb4', $column['charset'], "Column {$name} must be utf8mb4.");
            // utf8mb4_bin is for values that are compared byte by byte (keys, hashes, tokens).
            self::assertContains(
                $column['collation'],
                ['utf8mb4_unicode_ci', 'utf8mb4_bin'],
                "Column {$name} must use utf8mb4_unicode_ci (or utf8mb4_bin for exact comparison).",
            );
        }
    }

    public function test_rate_limits_table_has_the_columns_of_the_design(): void
    {
        // HLD §4.3: rate_limits (key, window_start, hits). One row per key and fixed window (HLD §4.5).
        $columns = $this->sql()->rows(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLLATION_NAME AS collation, '
            . 'COLUMN_DEFAULT AS standard FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            ['rate_limits'],
        );

        self::assertSame(
            [
                // Compared byte by byte: two different keys never share a counter.
                ['name' => 'key', 'type' => 'varchar(191)', 'nullable' => 'NO', 'collation' => 'utf8mb4_bin', 'standard' => null],
                ['name' => 'window_start', 'type' => 'datetime', 'nullable' => 'NO', 'collation' => null, 'standard' => null],
                ['name' => 'hits', 'type' => 'int(10) unsigned', 'nullable' => 'NO', 'collation' => null, 'standard' => null],
            ],
            $columns,
        );
    }

    public function test_rate_limits_has_one_row_per_key_and_window_and_can_be_cleaned_up_by_age(): void
    {
        $indexes = $this->sql()->rows(
            'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, COLUMN_NAME AS col FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            ['rate_limits'],
        );

        self::assertSame(
            [
                ['name' => 'PRIMARY', 'non_unique' => 0, 'col' => 'key'],
                ['name' => 'PRIMARY', 'non_unique' => 0, 'col' => 'window_start'],
                // The cron job deletes old windows (HLD §5.2 step 7) without reading the whole table.
                ['name' => 'rate_limits_window_start', 'non_unique' => 1, 'col' => 'window_start'],
            ],
            $indexes,
        );
    }

    public function test_rate_limits_counts_hits_per_key_and_window(): void
    {
        $hit = 'INSERT INTO rate_limits (`key`, window_start, hits) VALUES (?, ?, 1) '
            . 'ON DUPLICATE KEY UPDATE hits = hits + 1';

        $this->sql()->execute($hit, ['login:a', '2026-11-17 12:00:00']);
        $this->sql()->execute($hit, ['login:a', '2026-11-17 12:00:00']);
        $this->sql()->execute($hit, ['login:a', '2026-11-17 12:15:00']);
        // Not the same key: the comparison is exact, unlike the case-insensitive table default.
        $this->sql()->execute($hit, ['login:A', '2026-11-17 12:00:00']);

        self::assertSame(
            [
                ['key' => 'login:A', 'window_start' => '2026-11-17 12:00:00', 'hits' => 1],
                ['key' => 'login:a', 'window_start' => '2026-11-17 12:00:00', 'hits' => 2],
                ['key' => 'login:a', 'window_start' => '2026-11-17 12:15:00', 'hits' => 1],
            ],
            $this->sql()->rows('SELECT `key`, window_start, hits FROM rate_limits ORDER BY `key`, window_start'),
        );
    }
}
