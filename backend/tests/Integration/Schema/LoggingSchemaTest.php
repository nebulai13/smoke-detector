<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Application\Logging\SmokeLog;
use SmokeDetector\Domain\Day\DayEventType;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The tables of logging, check-in and day finalization as the migrations build them (HLD §4.3:
 * "smoke_logs", "day_summaries", "user_progress", "xp_events", "plan_history", "timezone_history").
 */
final class LoggingSchemaTest extends IntegrationTestCase
{
    private const string BIN = 'utf8mb4_bin';

    private const array TABLES = [
        'smoke_logs' => [
            'columns' => [
                'id' => ['uuid', 'NO', null],
                'user_id' => ['uuid', 'NO', null],
                'smoked_at' => ['datetime', 'NO', null],
                'local_date' => ['date', 'NO', null],
                'product_type' => ['varchar(32)', 'NO', self::BIN],
                'count' => ['tinyint(3) unsigned', 'NO', null],
                'created_at' => ['datetime', 'NO', null],
                'updated_at' => ['datetime', 'NO', null],
                'deleted_at' => ['datetime', 'YES', null],
            ],
            'primary' => ['id'],
            // The sessions of one day of one user are summed on every sync.
            'indexes' => [['user_id', 'local_date']],
        ],
        'day_summaries' => [
            'columns' => [
                'user_id' => ['uuid', 'NO', null],
                'local_date' => ['date', 'NO', null],
                'count' => ['smallint(5) unsigned', 'NO', null],
                'checked_in_at' => ['datetime', 'YES', null],
                // Everything from here on is written by finalization and NULL before it.
                'daily_limit' => ['smallint(5) unsigned', 'YES', null],
                'xp' => ['int(10) unsigned', 'YES', null],
                'events' => ['text', 'YES', self::BIN],
                'freeze_used' => ['tinyint(1)', 'YES', null],
                'finalized_at' => ['datetime', 'YES', null],
                'rules_version' => ['varchar(32)', 'YES', self::BIN],
            ],
            'primary' => ['user_id', 'local_date'],
            'indexes' => [],
        ],
        'user_progress' => [
            'columns' => [
                'user_id' => ['uuid', 'NO', null],
                'check_in_current' => ['smallint(5) unsigned', 'NO', null],
                'check_in_longest' => ['smallint(5) unsigned', 'NO', null],
                'under_limit_current' => ['smallint(5) unsigned', 'NO', null],
                'under_limit_longest' => ['smallint(5) unsigned', 'NO', null],
                'freezes_held' => ['tinyint(3) unsigned', 'NO', null],
                'xp_total' => ['bigint(20) unsigned', 'NO', null],
                'level' => ['smallint(5) unsigned', 'NO', null],
                'next_day' => ['date', 'NO', null],
                'finalize_due_at' => ['datetime', 'NO', null],
                'baseline_due_at' => ['datetime', 'NO', null],
            ],
            'primary' => ['user_id'],
            // The cron job asks every minute who is due, and must not read every user for it.
            'indexes' => [['finalize_due_at'], ['baseline_due_at']],
        ],
        'xp_events' => [
            'columns' => [
                'id' => ['uuid', 'NO', null],
                'user_id' => ['uuid', 'NO', null],
                'local_date' => ['date', 'NO', null],
                'source' => ['varchar(32)', 'NO', self::BIN],
                'amount' => ['int(10) unsigned', 'NO', null],
                'detail' => ['int(10) unsigned', 'YES', null],
                'created_at' => ['datetime', 'NO', null],
            ],
            'primary' => ['id'],
            'indexes' => [['user_id', 'local_date']],
        ],
        'plan_history' => [
            'columns' => [
                'user_id' => ['uuid', 'NO', null],
                'valid_from' => ['date', 'NO', null],
                'goal_mode' => ['varchar(16)', 'NO', self::BIN],
                'target' => ['smallint(5) unsigned', 'NO', null],
                'taper_speed' => ['varchar(16)', 'NO', self::BIN],
                'rules_version' => ['varchar(32)', 'NO', self::BIN],
            ],
            'primary' => ['user_id', 'valid_from'],
            'indexes' => [],
        ],
        'timezone_history' => [
            'columns' => [
                'user_id' => ['uuid', 'NO', null],
                'valid_from' => ['date', 'NO', null],
                'timezone' => ['varchar(64)', 'NO', self::BIN],
            ],
            'primary' => ['user_id', 'valid_from'],
            'indexes' => [],
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
    public function test_FR_LOG_02_the_table_has_the_columns_and_the_key_of_the_design(string $table): void
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
    public function test_FR_ACC_08_the_rows_of_the_table_go_with_their_account(string $table): void
    {
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
        );
    }

    public function test_every_value_the_code_can_store_fits_its_column(): void
    {
        self::assertSame(50, SmokeLog::MAX_COUNT);
        foreach (XpSource::cases() as $source) {
            self::assertLessThanOrEqual(32, strlen($source->value));
        }

        // The events of one day as they are stored, at their longest: every type once (a day never
        // has all of them), and four level-ups on top, each with a five-digit value.
        $events = [];
        foreach (DayEventType::cases() as $type) {
            $events[] = ['type' => $type->value, 'value' => 99999];
        }
        for ($level = 0; $level < 4; $level++) {
            $events[] = ['type' => DayEventType::LevelUp->value, 'value' => 99999];
        }
        // What a TEXT column holds.
        self::assertLessThan(65535, strlen(json_encode($events, JSON_THROW_ON_ERROR)));
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
