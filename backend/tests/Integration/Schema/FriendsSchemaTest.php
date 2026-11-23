<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The tables of the friends as the migrations build them (HLD §4.3: "invite_codes", "friendships",
 * "blocks").
 */
final class FriendsSchemaTest extends IntegrationTestCase
{
    private const string BIN = 'utf8mb4_bin';

    private const array TABLES = [
        'invite_codes' => [
            'columns' => [
                'user_id' => ['uuid', 'NO', null],
                'code' => ['varchar(10)', 'NO', self::BIN],
                'created_at' => ['datetime', 'NO', null],
            ],
            'primary' => ['user_id'],
            // A friend request starts from the code.
            'indexes' => [['code']],
            'references' => ['user_id'],
        ],
        'friendships' => [
            'columns' => [
                // The two users, the one with the smaller id (as text) first: one row per pair.
                'user_a' => ['uuid', 'NO', null],
                'user_b' => ['uuid', 'NO', null],
                'requested_by' => ['uuid', 'NO', null],
                'requested_at' => ['datetime', 'NO', null],
                // NULL while the request waits for its answer.
                'accepted_at' => ['datetime', 'YES', null],
            ],
            'primary' => ['user_a', 'user_b'],
            // The links of a user are found from either column.
            'indexes' => [['user_b']],
            'references' => ['user_a', 'user_b'],
        ],
        'blocks' => [
            'columns' => [
                'blocker_id' => ['uuid', 'NO', null],
                'blocked_id' => ['uuid', 'NO', null],
                'created_at' => ['datetime', 'NO', null],
            ],
            'primary' => ['blocker_id', 'blocked_id'],
            // "Has one of the two blocked the other?" is asked from both sides.
            'indexes' => [['blocked_id']],
            // In the order of the column names.
            'references' => ['blocked_id', 'blocker_id'],
        ],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function tables(): iterable
    {
        foreach (array_keys(self::TABLES) as $table) {
            yield $table => [$table];
        }
    }

    #[DataProvider('tables')]
    public function test_FR_FRND_01_the_table_has_the_columns_and_the_key_of_the_design(string $table): void
    {
        self::assertSame(self::TABLES[$table]['columns'], $this->columns($table));
        self::assertSame(self::TABLES[$table]['primary'], $this->indexes($table)['PRIMARY'] ?? null);
    }

    #[DataProvider('tables')]
    public function test_NFR_01_the_table_has_an_index_for_every_lookup_of_the_design(string $table): void
    {
        $indexed = array_values($this->indexes($table));

        foreach (self::TABLES[$table]['indexes'] as $columns) {
            self::assertContains($columns, $indexed, "{$table} needs an index on " . implode(', ', $columns) . '.');
        }
    }

    #[DataProvider('tables')]
    public function test_FR_ACC_08_the_rows_of_the_table_go_with_each_of_their_accounts(string $table): void
    {
        $expected = array_map(
            static fn (string $column): array => ['column' => $column, 'refers_to' => 'users', 'on_delete' => 'CASCADE'],
            self::TABLES[$table]['references'],
        );

        self::assertSame(
            $expected,
            $this->sql()->rows(
                'SELECT k.COLUMN_NAME AS `column`, k.REFERENCED_TABLE_NAME AS refers_to, r.DELETE_RULE AS on_delete '
                . 'FROM information_schema.KEY_COLUMN_USAGE k '
                . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
                . 'WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME',
                [$table],
            ),
        );
    }

    public function test_FR_FRND_01_an_invite_code_belongs_to_one_user(): void
    {
        self::assertSame(
            0,
            $this->sql()->value(
                "SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invite_codes' AND COLUMN_NAME = 'code'",
            ),
        );
        self::assertSame(10, InviteCode::LENGTH);
    }

    public function test_FR_FRND_01_nobody_is_their_own_friend_and_nobody_blocks_themselves(): void
    {
        $id = '0198a000-0000-7000-8000-000000000001';
        $this->sql()->execute(
            'INSERT INTO users (id, email, password_hash, display_name, avatar_key, locale, timezone, email_verified_at, created_at) '
            . "VALUES (?, 'anna@example.org', 'x', 'Anna', 'avatar-01', 'de', 'Europe/Berlin', NULL, '2026-11-17 12:00:00')",
            [$id],
        );

        foreach ([
            "INSERT INTO friendships VALUES (?, ?, ?, '2026-11-17 12:00:00', NULL)" => [$id, $id, $id],
            "INSERT INTO blocks VALUES (?, ?, '2026-11-17 12:00:00')" => [$id, $id],
        ] as $statement => $values) {
            $refused = false;
            try {
                $this->sql()->execute($statement, $values);
            } catch (\Throwable) {
                $refused = true;
            }
            self::assertTrue($refused, $statement);
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
