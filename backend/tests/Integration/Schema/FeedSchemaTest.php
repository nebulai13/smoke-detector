<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use SmokeDetector\Application\Feed\FeedEventType;
use SmokeDetector\Application\Feed\ReactionKey;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The table of the feed entries as its migration builds it (HLD §4.3: "feed_events").
 */
final class FeedSchemaTest extends IntegrationTestCase
{
    private const string BIN = 'utf8mb4_bin';

    public function test_FR_SOC_01_the_table_has_the_columns_and_the_keys_of_the_design(): void
    {
        self::assertSame(
            [
                // The order in which the entries were written. Never sent to anybody.
                'position' => ['bigint(20) unsigned', 'NO', null],
                // Random: what a client gets to name an entry says nothing about its time.
                'id' => ['uuid', 'NO', null],
                'user_id' => ['uuid', 'NO', null],
                'type' => ['varchar(24)', 'NO', self::BIN],
                'detail' => ['varchar(32)', 'YES', self::BIN],
                'other_user_id' => ['uuid', 'YES', null],
                'created_at' => ['datetime', 'NO', null],
            ],
            $this->columns('feed_events'),
        );
        $indexes = $this->indexes('feed_events');
        self::assertSame(['position'], $indexes['PRIMARY'] ?? null);
        self::assertContains(['id'], array_values($indexes));
        // The newest entries of some users are found by an index.
        self::assertContains(['user_id', 'position'], array_values($indexes));
        self::assertSame(
            [['unique' => 0]],
            $this->sql()->rows("SELECT DISTINCT NON_UNIQUE AS `unique` FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feed_events' AND COLUMN_NAME = 'id'"),
        );
    }

    public function test_FR_ACC_08_an_entry_goes_with_its_user_and_forgets_an_opponent_who_goes(): void
    {
        self::assertSame(
            [
                ['column' => 'other_user_id', 'refers_to' => 'users', 'on_delete' => 'SET NULL'],
                ['column' => 'user_id', 'refers_to' => 'users', 'on_delete' => 'CASCADE'],
            ],
            $this->sql()->rows(
                'SELECT k.COLUMN_NAME AS `column`, k.REFERENCED_TABLE_NAME AS refers_to, r.DELETE_RULE AS on_delete '
                . 'FROM information_schema.KEY_COLUMN_USAGE k '
                . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
                . "WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'feed_events' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME",
            ),
        );
    }

    public function test_FR_SOC_02_the_table_of_the_reactions_has_the_columns_and_the_keys_of_the_design(): void
    {
        self::assertSame(
            [
                'event_id' => ['uuid', 'NO', null],
                'user_id' => ['uuid', 'NO', null],
                'reaction' => ['varchar(16)', 'NO', self::BIN],
                'created_at' => ['datetime', 'NO', null],
            ],
            $this->columns('feed_reactions'),
        );
        $indexes = $this->indexes('feed_reactions');
        // A user has one reaction to an entry.
        self::assertSame(['event_id', 'user_id'], $indexes['PRIMARY'] ?? null);
        // What a user sent is counted for a badge and exported.
        self::assertContains('user_id', array_map(static fn (array $columns): mixed => $columns[0], array_values($indexes)));
        self::assertSame(
            [
                ['column' => 'event_id', 'refers_to' => 'feed_events', 'on_delete' => 'CASCADE'],
                ['column' => 'user_id', 'refers_to' => 'users', 'on_delete' => 'CASCADE'],
            ],
            $this->sql()->rows(
                'SELECT k.COLUMN_NAME AS `column`, k.REFERENCED_TABLE_NAME AS refers_to, r.DELETE_RULE AS on_delete '
                . 'FROM information_schema.KEY_COLUMN_USAGE k '
                . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
                . "WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'feed_reactions' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME",
            ),
        );
    }

    public function test_every_value_the_code_can_store_fits_its_column(): void
    {
        foreach (FeedEventType::cases() as $type) {
            self::assertLessThanOrEqual(24, strlen($type->value));
        }
        foreach (ReactionKey::cases() as $reaction) {
            self::assertLessThanOrEqual(16, strlen($reaction->value));
        }
        foreach ([...Badge::cases(), ...HealthMilestone::cases()] as $detail) {
            self::assertLessThanOrEqual(32, strlen($detail->value));
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
