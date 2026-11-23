<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Competition\DuelWinner;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The table of the duels as its migration builds it (HLD §4.3: "duels").
 */
final class DuelsSchemaTest extends IntegrationTestCase
{
    private const string BIN = 'utf8mb4_bin';

    public function test_FR_COMP_01_the_table_has_the_columns_and_the_key_of_the_design(): void
    {
        self::assertSame(
            [
                'id' => ['uuid', 'NO', null],
                // NULL once the account is gone: the duel stays for the other user.
                'challenger_id' => ['uuid', 'YES', null],
                'opponent_id' => ['uuid', 'YES', null],
                'period_days' => ['smallint(5) unsigned', 'NO', null],
                'status' => ['varchar(16)', 'NO', self::BIN],
                'created_at' => ['datetime', 'NO', null],
                'expires_at' => ['datetime', 'NO', null],
                'answered_at' => ['datetime', 'YES', null],
                // The first day of the duel in the calendar of each of the two.
                'challenger_start' => ['date', 'YES', null],
                'opponent_start' => ['date', 'YES', null],
                // Everything from here on is written when the duel is decided and NULL before.
                'decided_at' => ['datetime', 'YES', null],
                'winner' => ['varchar(16)', 'YES', self::BIN],
                'challenger_b0_tenths' => ['smallint(5) unsigned', 'YES', null],
                'challenger_actual_tenths' => ['int(10) unsigned', 'YES', null],
                'challenger_expected_tenths' => ['int(10) unsigned', 'YES', null],
                'challenger_smoke_free_days' => ['smallint(5) unsigned', 'YES', null],
                'challenger_check_ins' => ['smallint(5) unsigned', 'YES', null],
                'challenger_xp' => ['int(10) unsigned', 'YES', null],
                'opponent_b0_tenths' => ['smallint(5) unsigned', 'YES', null],
                'opponent_actual_tenths' => ['int(10) unsigned', 'YES', null],
                'opponent_expected_tenths' => ['int(10) unsigned', 'YES', null],
                'opponent_smoke_free_days' => ['smallint(5) unsigned', 'YES', null],
                'opponent_check_ins' => ['smallint(5) unsigned', 'YES', null],
                'opponent_xp' => ['int(10) unsigned', 'YES', null],
                'rules_version' => ['varchar(32)', 'YES', self::BIN],
            ],
            $this->columns('duels'),
        );
        self::assertSame(['id'], $this->indexes('duels')['PRIMARY'] ?? null);
    }

    public function test_NFR_01_the_table_has_an_index_for_every_lookup_of_the_design(): void
    {
        $indexed = array_values($this->indexes('duels'));

        // The duels of a user from either side, and the proposals whose time is over.
        foreach ([['challenger_id'], ['opponent_id'], ['status', 'expires_at']] as $columns) {
            self::assertContains($columns, $indexed, 'duels needs an index on ' . implode(', ', $columns) . '.');
        }
    }

    public function test_FR_ACC_08_a_duel_outlives_an_account_without_pointing_at_it(): void
    {
        self::assertSame(
            [
                ['column' => 'challenger_id', 'refers_to' => 'users', 'on_delete' => 'SET NULL'],
                ['column' => 'opponent_id', 'refers_to' => 'users', 'on_delete' => 'SET NULL'],
            ],
            $this->sql()->rows(
                'SELECT k.COLUMN_NAME AS `column`, k.REFERENCED_TABLE_NAME AS refers_to, r.DELETE_RULE AS on_delete '
                . 'FROM information_schema.KEY_COLUMN_USAGE k '
                . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
                . "WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'duels' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME",
            ),
        );
    }

    public function test_every_value_the_code_can_store_fits_its_column(): void
    {
        foreach (DuelStatus::cases() as $status) {
            self::assertLessThanOrEqual(16, strlen($status->value));
        }
        foreach (DuelWinner::cases() as $winner) {
            self::assertLessThanOrEqual(16, strlen($winner->value));
        }
        // The longest duel against the highest baseline the scoring knows (100.0 a day), with the
        // most that can be logged on a day (300): far below what the columns hold.
        self::assertSame(180, max(Duel::PERIODS));
        self::assertLessThan(4_294_967_295, 180 * 300 * 10);
        self::assertLessThan(65_535, 1000);
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
