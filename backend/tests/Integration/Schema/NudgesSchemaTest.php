<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The table of the nudges as its migration builds it (HLD §4.3: "nudges").
 */
final class NudgesSchemaTest extends IntegrationTestCase
{
    public function test_FR_SOC_03_the_table_has_the_columns_and_the_keys_of_the_design(): void
    {
        self::assertSame(
            [
                'from_user_id' => ['uuid', 'NO', null],
                'to_user_id' => ['uuid', 'NO', null],
                // The local day of the sender: one nudge per friend and day.
                'day' => ['date', 'NO', null],
                'created_at' => ['datetime', 'NO', null],
                // Whether the receiver had not checked in. Never told to the sender.
                'delivered' => ['tinyint(1)', 'NO', null],
            ],
            $this->columns('nudges'),
        );
        $indexes = $this->indexes('nudges');
        self::assertSame(['from_user_id', 'to_user_id', 'day'], $indexes['PRIMARY'] ?? null);
        // The nudges of today to a user are found by an index.
        self::assertContains(['to_user_id', 'created_at'], array_values($indexes));
    }

    public function test_FR_ACC_08_a_nudge_goes_with_either_of_its_accounts(): void
    {
        self::assertSame(
            [
                ['column' => 'from_user_id', 'refers_to' => 'users', 'on_delete' => 'CASCADE'],
                ['column' => 'to_user_id', 'refers_to' => 'users', 'on_delete' => 'CASCADE'],
            ],
            $this->sql()->rows(
                'SELECT k.COLUMN_NAME AS `column`, k.REFERENCED_TABLE_NAME AS refers_to, r.DELETE_RULE AS on_delete '
                . 'FROM information_schema.KEY_COLUMN_USAGE k '
                . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
                . "WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'nudges' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME",
            ),
        );
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
