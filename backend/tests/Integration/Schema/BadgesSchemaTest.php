<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The table of the badges as its migration builds it (HLD §4.3: "user_badges").
 */
final class BadgesSchemaTest extends IntegrationTestCase
{
    private const string BIN = 'utf8mb4_bin';

    public function test_FR_GAME_08_the_table_has_the_columns_and_the_key_of_the_design(): void
    {
        self::assertSame(
            [
                'user_id' => ['uuid', 'NO', null],
                'badge_key' => ['varchar(32)', 'NO', self::BIN],
                'awarded_at' => ['datetime', 'NO', null],
            ],
            $this->columns('user_badges'),
        );
        // A user holds a badge once.
        self::assertSame(['user_id', 'badge_key'], $this->indexes('user_badges')['PRIMARY'] ?? null);
    }

    public function test_FR_ACC_08_the_rows_of_the_table_go_with_their_account(): void
    {
        self::assertSame(
            [['column' => 'user_id', 'refers_to' => 'users', 'on_delete' => 'CASCADE']],
            $this->sql()->rows(
                'SELECT k.COLUMN_NAME AS `column`, k.REFERENCED_TABLE_NAME AS refers_to, r.DELETE_RULE AS on_delete '
                . 'FROM information_schema.KEY_COLUMN_USAGE k '
                . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
                . "WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'user_badges' AND k.REFERENCED_TABLE_NAME IS NOT NULL",
            ),
        );
    }

    public function test_every_value_the_code_can_store_fits_its_column(): void
    {
        foreach (Badge::cases() as $badge) {
            self::assertLessThanOrEqual(32, strlen($badge->value));
        }
    }

    /**
     * @return array<string, array{mixed, mixed, mixed}> Column => [type, nullable, collation].
     */
    private function columns(string $table): array
    {
        $columns = [];
        $rows = $this->sql()->rows(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLLATION_NAME AS collation '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        );
        foreach ($rows as $row) {
            self::assertIsString($row['name']);
            $columns[$row['name']] = [$row['type'], $row['nullable'], $row['collation']];
        }

        return $columns;
    }

    /**
     * @return array<string, list<mixed>> Index => columns, unique or not.
     */
    private function indexes(string $table): array
    {
        $indexes = [];
        $rows = $this->sql()->rows(
            'SELECT INDEX_NAME AS name, COLUMN_NAME AS col FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        );
        foreach ($rows as $row) {
            self::assertIsString($row['name']);
            $indexes[$row['name']][] = $row['col'];
        }

        return $indexes;
    }
}
