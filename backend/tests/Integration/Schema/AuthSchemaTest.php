<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Schema;

use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The tables of the auth step as HLD §4.3 lists them: users, consents, refresh_tokens,
 * one_time_tokens, mail_outbox.
 */
final class AuthSchemaTest extends IntegrationTestCase
{
    private const string BIN = 'utf8mb4_bin';
    private const string TEXT = 'utf8mb4_unicode_ci';

    public function test_users_has_the_columns_of_the_design(): void
    {
        self::assertSame(
            [
                'id' => ['uuid', 'NO', null],
                // Compared byte by byte: under the table default "é" and "e" would be the same address.
                'email' => ['varchar(254)', 'NO', self::BIN],
                'password_hash' => ['varchar(255)', 'NO', self::BIN],
                'display_name' => ['varchar(30)', 'NO', self::TEXT],
                'avatar_key' => ['varchar(32)', 'NO', self::BIN],
                'locale' => ['varchar(8)', 'NO', self::BIN],
                'timezone' => ['varchar(64)', 'NO', self::BIN],
                'email_verified_at' => ['datetime', 'YES', null],
                'created_at' => ['datetime', 'NO', null],
                'token_version' => ['int(10) unsigned', 'NO', null],
            ],
            $this->columns('users'),
        );
        self::assertSame(
            [
                'PRIMARY' => [true, ['id']],
                'users_email' => [true, ['email']],
                'users_unverified' => [false, ['email_verified_at', 'created_at']],
            ],
            $this->indexes('users'),
        );
    }

    public function test_consents_has_the_columns_of_the_design(): void
    {
        self::assertSame(
            [
                'user_id' => ['uuid', 'NO', null],
                'type' => ['varchar(16)', 'NO', self::BIN],
                'version' => ['varchar(32)', 'NO', self::BIN],
                'granted_at' => ['datetime', 'NO', null],
            ],
            $this->columns('consents'),
        );
        self::assertSame(['PRIMARY' => [true, ['user_id', 'type', 'version']]], $this->indexes('consents'));
    }

    public function test_refresh_tokens_has_the_columns_of_the_design(): void
    {
        self::assertSame(
            [
                'id' => ['uuid', 'NO', null],
                'user_id' => ['uuid', 'NO', null],
                'key_hash' => ['char(64)', 'NO', self::BIN],
                'token_hash' => ['char(64)', 'NO', self::BIN],
                'device_label' => ['varchar(100)', 'YES', self::TEXT],
                'created_at' => ['datetime', 'NO', null],
                'rotated_at' => ['datetime', 'YES', null],
                'expires_at' => ['datetime', 'NO', null],
                'revoked_at' => ['datetime', 'YES', null],
                // How often the token was exchanged. The secret of a token follows from it (HLD §4.5).
                'generation' => ['int(10) unsigned', 'NO', null],
            ],
            $this->columns('refresh_tokens'),
        );
        self::assertSame(
            [
                'PRIMARY' => [true, ['id']],
                'refresh_tokens_expires_at' => [false, ['expires_at']],
                'refresh_tokens_key_hash' => [true, ['key_hash']],
                'refresh_tokens_token_hash' => [true, ['token_hash']],
                'refresh_tokens_user' => [false, ['user_id']],
            ],
            $this->indexes('refresh_tokens'),
        );
    }

    public function test_one_time_tokens_has_the_columns_of_the_design(): void
    {
        self::assertSame(
            [
                'id' => ['uuid', 'NO', null],
                'user_id' => ['uuid', 'NO', null],
                'purpose' => ['varchar(32)', 'NO', self::BIN],
                'token_hash' => ['char(64)', 'NO', self::BIN],
                'expires_at' => ['datetime', 'NO', null],
                'used_at' => ['datetime', 'YES', null],
                // Wrong passwords entered with a confirmation link: the fifth deletes it.
                'failed_attempts' => ['tinyint(3) unsigned', 'NO', null],
            ],
            $this->columns('one_time_tokens'),
        );
        self::assertSame(
            [
                'PRIMARY' => [true, ['id']],
                'one_time_tokens_expires_at' => [false, ['expires_at']],
                'one_time_tokens_token_hash' => [true, ['token_hash']],
                // One token per user and purpose: a new one takes the place of the old one in one step.
                'one_time_tokens_user_purpose' => [true, ['user_id', 'purpose']],
            ],
            $this->indexes('one_time_tokens'),
        );
    }

    public function test_mail_outbox_has_the_columns_of_the_design(): void
    {
        self::assertSame(
            [
                'id' => ['uuid', 'NO', null],
                // Every mail is about one account, and goes when the account goes.
                'user_id' => ['uuid', 'NO', null],
                'template' => ['varchar(64)', 'NO', self::BIN],
                'locale' => ['varchar(8)', 'NO', self::BIN],
                'recipient' => ['varchar(254)', 'NO', self::BIN],
                // The links of the mail, encrypted: a link carries its token in plain text.
                'sealed_values' => ['varchar(2048)', 'NO', self::BIN],
                'created_at' => ['datetime', 'NO', null],
                'not_before' => ['datetime', 'NO', null],
                'attempts' => ['tinyint(3) unsigned', 'NO', null],
            ],
            $this->columns('mail_outbox'),
        );
        self::assertSame(
            [
                'PRIMARY' => [true, ['id']],
                // For the foreign key only. Deliberately no index on not_before: see the migration.
                'mail_outbox_user' => [false, ['user_id']],
            ],
            $this->indexes('mail_outbox'),
        );
    }

    public function test_everything_that_belongs_to_a_user_is_deleted_with_the_user(): void
    {
        $references = $this->sql()->rows(
            'SELECT k.TABLE_NAME AS tbl, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS parent, '
            . 'k.REFERENCED_COLUMN_NAME AS parent_col, r.DELETE_RULE AS on_delete '
            . 'FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
            . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
            . 'WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.COLUMN_NAME',
        );

        $row = static fn (string $table, string $column = 'user_id', string $onDelete = 'CASCADE'): array => [
            'tbl' => $table, 'col' => $column, 'parent' => 'users', 'parent_col' => 'id', 'on_delete' => $onDelete,
        ];
        self::assertSame(
            [
                $row('baseline_history'),
                // A block goes with either of its two accounts, and so does a friendship.
                $row('blocks', 'blocked_id'), $row('blocks', 'blocker_id'),
                $row('consents'), $row('day_summaries'),
                // A duel stays for the other user and stops pointing at the account that is gone.
                $row('duels', 'challenger_id', 'SET NULL'), $row('duels', 'opponent_id', 'SET NULL'),
                // A feed entry goes with its user, and forgets an opponent who goes.
                $row('feed_events', 'other_user_id', 'SET NULL'), $row('feed_events'),
                // A reaction goes with its entry and with the user who reacted.
                ['tbl' => 'feed_reactions', 'col' => 'event_id', 'parent' => 'feed_events', 'parent_col' => 'id', 'on_delete' => 'CASCADE'], $row('feed_reactions'),
                $row('friendships', 'user_a'), $row('friendships', 'user_b'),
                $row('invite_codes'), $row('league_results'), $row('mail_outbox'),
                // A nudge goes with its sender and with its receiver.
                $row('nudges', 'from_user_id'), $row('nudges', 'to_user_id'),
                $row('one_time_tokens'), $row('plans'),
                // The database sorts "s" before "_".
                $row('plan_history'), $row('refresh_tokens'), $row('smoke_logs'), $row('timezone_history'), $row('user_badges'), $row('user_milestones'), $row('user_progress'),
                $row('user_settings'), $row('xp_events'),
            ],
            $references,
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
     * @return array<string, array{bool, list<mixed>}> Index => [unique, columns].
     */
    private function indexes(string $table): array
    {
        $indexes = [];
        $rows = $this->sql()->rows(
            'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, COLUMN_NAME AS col FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        );
        foreach ($rows as $row) {
            self::assertIsString($row['name']);
            $indexes[$row['name']] ??= [$row['non_unique'] === 0, []];
            $indexes[$row['name']][1][] = $row['col'];
        }
        // The order of the database depends on its collation. PRIMARY first, then by name.
        uksort($indexes, static fn (string $a, string $b): int => [$a !== 'PRIMARY', $a] <=> [$b !== 'PRIMARY', $b]);

        return $indexes;
    }
}
