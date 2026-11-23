<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The table of the league results as its migration builds it (HLD §4.3: "league_results").
 */
final class LeaguesSchemaTest extends IntegrationTestCase
{
    private const string BIN = 'utf8mb4_bin';

    public function test_FR_COMP_06_the_table_has_the_columns_and_the_key_of_the_design(): void
    {
        self::assertSame(
            [
                'user_id' => ['uuid', 'NO', null],
                'period_type' => ['varchar(16)', 'NO', self::BIN],
                'period_key' => ['varchar(16)', 'NO', self::BIN],
                'b0_tenths' => ['smallint(5) unsigned', 'NO', null],
                'actual_tenths' => ['int(10) unsigned', 'NO', null],
                'expected_tenths' => ['int(10) unsigned', 'NO', null],
                'smoke_free_days' => ['smallint(5) unsigned', 'NO', null],
                'check_ins' => ['smallint(5) unsigned', 'NO', null],
                'counted_days' => ['smallint(5) unsigned', 'NO', null],
                'rules_version' => ['varchar(32)', 'NO', self::BIN],
                'scored_at' => ['datetime', 'NO', null],
                // When the cron step looks at the result next, to rank it.
                'check_at' => ['datetime', 'NO', null],
                // Written when the rank is decided and NULL before.
                'rank' => ['smallint(5) unsigned', 'YES', null],
                'participants' => ['smallint(5) unsigned', 'YES', null],
                'xp' => ['int(10) unsigned', 'YES', null],
                'ranked_at' => ['datetime', 'YES', null],
            ],
            $this->columns('league_results'),
        );
        // One result per user and period.
        self::assertSame(['user_id', 'period_type', 'period_key'], $this->indexes('league_results')['PRIMARY'] ?? null);
    }

    public function test_NFR_01_the_results_that_wait_for_their_rank_are_found_by_an_index(): void
    {
        self::assertContains(['ranked_at', 'check_at'], array_values($this->indexes('league_results')));
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
                . "WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'league_results' AND k.REFERENCED_TABLE_NAME IS NOT NULL",
            ),
        );
    }

    public function test_every_value_the_code_can_store_fits_its_column(): void
    {
        foreach (LeaguePeriod::cases() as $type) {
            self::assertLessThanOrEqual(16, strlen($type->value));
        }
        // The longest key, and the longest period (a half-year of 184 days) against the highest
        // baseline the scoring knows (100.0 a day) with the most that can be logged on a day (300).
        self::assertLessThanOrEqual(16, strlen('2026-W53'));
        self::assertLessThan(4_294_967_295, 184 * 300 * 10);
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
