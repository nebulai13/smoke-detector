<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The tables of the reached health milestones and of the user settings as their migrations build
 * them (HLD §4.3: "user_milestones", "user_settings").
 */
final class MilestonesSchemaTest extends IntegrationTestCase
{
    private const string BIN = 'utf8mb4_bin';

    public function test_FR_GAME_09_the_tables_have_the_columns_and_the_keys_of_the_design(): void
    {
        self::assertSame(
            [
                'user_id' => ['uuid', 'NO', null],
                'milestone_key' => ['varchar(32)', 'NO', self::BIN],
                'reached_at' => ['datetime', 'NO', null],
            ],
            $this->columns('user_milestones'),
        );
        // A user has reached a milestone once.
        self::assertSame(['user_id', 'milestone_key'], $this->indexes('user_milestones')['PRIMARY'] ?? null);
        self::assertSame(
            [
                'user_id' => ['uuid', 'NO', null],
                'milestone_sharing' => ['varchar(16)', 'NO', self::BIN],
            ],
            $this->columns('user_settings'),
        );
        // One row per user, made by the first choice.
        self::assertSame(['user_id'], $this->indexes('user_settings')['PRIMARY'] ?? null);
    }

    public function test_FR_ACC_08_the_rows_of_both_tables_go_with_their_account(): void
    {
        foreach (['user_milestones', 'user_settings'] as $table) {
            self::assertSame(
                [['column' => 'user_id', 'refers_to' => 'users', 'on_delete' => 'CASCADE']],
                $this->sql()->rows(
                    'SELECT k.COLUMN_NAME AS `column`, k.REFERENCED_TABLE_NAME AS refers_to, r.DELETE_RULE AS on_delete '
                    . 'FROM information_schema.KEY_COLUMN_USAGE k '
                    . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                    . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
                    . 'WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
                    [$table],
                ),
                $table,
            );
        }
    }

    public function test_every_value_the_code_can_store_fits_its_column(): void
    {
        foreach (HealthMilestone::cases() as $milestone) {
            self::assertLessThanOrEqual(32, strlen($milestone->value));
        }
        foreach (MilestoneSharing::cases() as $sharing) {
            self::assertLessThanOrEqual(16, strlen($sharing->value));
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
