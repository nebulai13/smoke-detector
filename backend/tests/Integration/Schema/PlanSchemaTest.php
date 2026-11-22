<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The tables of the reduction plan as the migrations build them (HLD §4.3 "plans",
 * "baseline_history").
 */
final class PlanSchemaTest extends IntegrationTestCase
{
    public function test_FR_ACC_06_plans_has_the_columns_of_the_design_and_one_row_per_user(): void
    {
        self::assertSame(
            [
                'user_id' => ['uuid', 'NO', null],
                'goal_mode' => ['varchar(16)', 'NO', 'utf8mb4_bin'],
                'target' => ['smallint(5) unsigned', 'NO', null],
                'taper_speed' => ['varchar(16)', 'NO', 'utf8mb4_bin'],
                'default_product' => ['varchar(32)', 'NO', 'utf8mb4_bin'],
                'start_date' => ['date', 'NO', null],
                'rules_version' => ['varchar(32)', 'NO', 'utf8mb4_bin'],
            ],
            $this->columns('plans'),
        );
        self::assertSame(['PRIMARY' => ['user_id']], $this->uniqueIndexes('plans'));
    }

    public function test_FR_PLAN_02_baseline_history_has_the_columns_of_the_design_and_one_row_per_user_and_day(): void
    {
        self::assertSame(
            [
                'user_id' => ['uuid', 'NO', null],
                'valid_from' => ['date', 'NO', null],
                // One decimal place, up to 999.9 (BaselineEntry::MAX_TENTHS).
                'value' => ['decimal(4,1)', 'NO', null],
                'source' => ['varchar(32)', 'NO', 'utf8mb4_bin'],
                'w_mean' => ['double', 'YES', null],
                'm_mean' => ['double', 'YES', null],
                'w_days' => ['tinyint(3) unsigned', 'YES', null],
                'm_days' => ['tinyint(3) unsigned', 'YES', null],
                'rules_version' => ['varchar(32)', 'NO', 'utf8mb4_bin'],
            ],
            $this->columns('baseline_history'),
        );
        // The primary key is also what makes "the baseline of a user on a day" one value, and it
        // serves the two lookups: latest entry of a user, all entries of a user in order.
        self::assertSame(['PRIMARY' => ['user_id', 'valid_from']], $this->uniqueIndexes('baseline_history'));
        self::assertSame(9999, BaselineEntry::MAX_TENTHS);
    }

    public function test_every_value_the_code_can_store_fits_its_column(): void
    {
        foreach (ProductType::cases() as $type) {
            self::assertLessThanOrEqual(32, strlen($type->value));
        }
        foreach (BaselineSource::cases() as $source) {
            self::assertLessThanOrEqual(32, strlen($source->value));
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
     * @return array<string, list<mixed>> Unique index => columns.
     */
    private function uniqueIndexes(string $table): array
    {
        $indexes = [];
        $rows = $this->sql()->rows(
            'SELECT INDEX_NAME AS name, COLUMN_NAME AS col FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        );
        foreach ($rows as $row) {
            self::assertIsString($row['name']);
            $indexes[$row['name']][] = $row['col'];
        }

        return $indexes;
    }
}
