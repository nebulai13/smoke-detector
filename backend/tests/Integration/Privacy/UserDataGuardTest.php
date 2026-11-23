<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Privacy;

use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\Privacy\UserDataGuard;

/**
 * No table with data of a user can be forgotten by the data export or by account deletion
 * (HLD §4.5 "Account", FR-ACC-07, FR-ACC-08).
 *
 * The first test reads the schema the migrations built and compares it with the user data registry
 * of the application. A migration that adds a table makes it fail until the table is either
 * registered with an exporter and an eraser (Infrastructure\Privacy\UserDataTables) or added to
 * NOT_USER_DATA below with the reason. The other tests prove that the check really notices.
 */
final class UserDataGuardTest extends IntegrationTestCase
{
    /**
     * Tables that need neither an exporter nor an eraser, and why. Adding a table here is a privacy
     * decision: it says that nothing in the table belongs to a user. When in doubt, register it.
     */
    private const array NOT_USER_DATA = [
        'phinxlog' => 'The migration log of Phinx: which migrations ran and when. Nothing about users.',
        'rate_limits' => 'Counters per rule and SHA-256 of an email address, user id or client address, deleted '
            . 'after two days. They cannot be listed for a user, so there is no exporter. Account deletion removes '
            . 'the counters of the user\'s email address and id itself (DeleteAccount, RateLimiter::forget), '
            . 'outside the transaction, because counters are never part of one.',
    ];

    /** Tables the tests below create. Dropped after each test. */
    private const array PROBES = ['guard_probe_grandchild', 'guard_probe_child', 'guard_probe'];

    protected function tearDown(): void
    {
        foreach (self::PROBES as $table) {
            // Fixed names from the constant above.
            $this->pdo()->exec("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function test_FR_ACC_07_every_table_of_the_schema_is_registered_for_export_and_erasure_or_deliberately_excluded(): void
    {
        self::assertSame([], UserDataGuard::violations($this->sql(), $this->registeredTables(), self::NOT_USER_DATA));
    }

    public function test_FR_ACC_08_the_tables_with_user_data_today_are_all_registered(): void
    {
        // Spelled out once, so that taking a table OUT of the registry is noticed as well.
        $registered = $this->registeredTables();
        sort($registered);

        self::assertSame(
            [
                'baseline_history', 'blocks', 'consents', 'day_summaries', 'duels', 'friendships', 'invite_codes', 'mail_outbox', 'one_time_tokens',
                'plan_history', 'plans', 'refresh_tokens', 'smoke_logs', 'timezone_history', 'user_progress', 'xp_events',
            ],
            $registered,
        );
    }

    public function test_a_new_table_with_a_foreign_key_to_users_is_reported(): void
    {
        $this->pdo()->exec(
            'CREATE TABLE `guard_probe` (`id` INT NOT NULL PRIMARY KEY, `owner` UUID NOT NULL, '
            . 'CONSTRAINT `guard_probe_fk` FOREIGN KEY (`owner`) REFERENCES `users` (`id`)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        self::assertSame(
            ['Table `guard_probe` is neither registered in the user data registry nor on the exclusion list, '
                . 'and it refers to users through the foreign key guard_probe.owner → users.'],
            UserDataGuard::violations($this->sql(), $this->registeredTables(), self::NOT_USER_DATA),
        );
    }

    public function test_a_new_table_with_a_user_id_column_and_no_foreign_key_is_reported(): void
    {
        $this->pdo()->exec(
            'CREATE TABLE `guard_probe` (`id` INT NOT NULL PRIMARY KEY, `user_id` UUID NOT NULL, `from_user` UUID NULL, `username` VARCHAR(10) NULL) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        self::assertSame(
            ['Table `guard_probe` is neither registered in the user data registry nor on the exclusion list, '
                . 'and it has the column user_id, from_user.'],
            UserDataGuard::violations($this->sql(), $this->registeredTables(), self::NOT_USER_DATA),
        );
    }

    public function test_a_table_that_refers_to_users_only_through_other_tables_is_reported(): void
    {
        $this->pdo()->exec(
            'CREATE TABLE `guard_probe` (`id` INT NOT NULL PRIMARY KEY, `user_id` UUID NOT NULL, '
            . 'CONSTRAINT `guard_probe_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->pdo()->exec(
            'CREATE TABLE `guard_probe_child` (`id` INT NOT NULL PRIMARY KEY, `probe_id` INT NOT NULL, '
            . 'CONSTRAINT `guard_probe_child_fk` FOREIGN KEY (`probe_id`) REFERENCES `guard_probe` (`id`)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->pdo()->exec(
            'CREATE TABLE `guard_probe_grandchild` (`id` INT NOT NULL PRIMARY KEY, `child_id` INT NOT NULL, '
            . 'CONSTRAINT `guard_probe_grandchild_fk` FOREIGN KEY (`child_id`) REFERENCES `guard_probe_child` (`id`)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        self::assertSame(
            [
                'Table `guard_probe_child` is neither registered in the user data registry nor on the exclusion list, '
                    . 'and it refers to users through the foreign key guard_probe_child.probe_id → guard_probe.user_id → users.',
                'Table `guard_probe_grandchild` is neither registered in the user data registry nor on the exclusion list, '
                    . 'and it refers to users through the foreign key '
                    . 'guard_probe_grandchild.child_id → guard_probe_child.probe_id → guard_probe.user_id → users.',
            ],
            // The parent is registered here, the two below it are not.
            UserDataGuard::violations($this->sql(), [...$this->registeredTables(), 'guard_probe'], self::NOT_USER_DATA),
        );
    }

    public function test_a_new_table_without_any_sign_of_a_user_still_has_to_be_accounted_for(): void
    {
        // A table can hold data of a user without a foreign key or a telling column name.
        $this->pdo()->exec(
            'CREATE TABLE `guard_probe` (`id` INT NOT NULL PRIMARY KEY, `actor` UUID NOT NULL) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        self::assertSame(
            ['Table `guard_probe` is neither registered in the user data registry nor on the exclusion list. '
                . 'Decide whether it holds data of a user.'],
            UserDataGuard::violations($this->sql(), $this->registeredTables(), self::NOT_USER_DATA),
        );
    }

    public function test_a_reported_table_is_settled_by_registering_it_or_by_excluding_it_with_a_reason(): void
    {
        $this->pdo()->exec(
            'CREATE TABLE `guard_probe` (`id` INT NOT NULL PRIMARY KEY, `user_id` UUID NOT NULL, '
            . 'CONSTRAINT `guard_probe_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $registered = $this->registeredTables();

        self::assertCount(1, UserDataGuard::violations($this->sql(), $registered, self::NOT_USER_DATA));
        self::assertSame([], UserDataGuard::violations($this->sql(), [...$registered, 'guard_probe'], self::NOT_USER_DATA));
        self::assertSame([], UserDataGuard::violations($this->sql(), $registered, self::NOT_USER_DATA + ['guard_probe' => 'Only for this test.']));
        self::assertSame(
            ['Table `guard_probe` is on the exclusion list without a reason.'],
            UserDataGuard::violations($this->sql(), $registered, self::NOT_USER_DATA + ['guard_probe' => ' ']),
        );
        self::assertSame(
            ['Table `guard_probe` is registered for export and erasure and excluded at the same time.'],
            UserDataGuard::violations($this->sql(), [...$registered, 'guard_probe'], self::NOT_USER_DATA + ['guard_probe' => 'Only for this test.']),
        );
    }

    public function test_a_registered_or_excluded_table_that_does_not_exist_is_reported(): void
    {
        // A misspelled name would otherwise account for nothing.
        self::assertSame(
            [
                'Table `smoke_log` is registered in the user data registry but does not exist.',
                'Table `phinx_log` is on the exclusion list but does not exist.',
            ],
            UserDataGuard::violations($this->sql(), [...$this->registeredTables(), 'smoke_log'], self::NOT_USER_DATA + ['phinx_log' => 'A typo.']),
        );
    }

    public function test_without_the_registry_every_table_with_user_data_is_reported(): void
    {
        $violations = UserDataGuard::violations($this->sql(), [], self::NOT_USER_DATA);

        // In the order of the database, which sorts "s" before "_".
        $tables = [
            'baseline_history', 'blocks', 'consents', 'day_summaries', 'duels', 'friendships', 'invite_codes', 'mail_outbox', 'one_time_tokens', 'plans',
            'plan_history', 'refresh_tokens', 'smoke_logs', 'timezone_history', 'user_progress', 'xp_events',
        ];
        // The tables two users share have no column "user_id": they name both users.
        $shared = ['blocks' => 'blocked_id', 'duels' => 'challenger_id', 'friendships' => 'user_a'];
        self::assertCount(count($tables), $violations);
        foreach ($tables as $index => $table) {
            self::assertStringStartsWith("Table `{$table}` is neither registered", $violations[$index]);
            $column = $shared[$table] ?? 'user_id';
            self::assertStringContainsString("{$table}.{$column} → users", $violations[$index]);
            if (!isset($shared[$table])) {
                self::assertStringContainsString("it refers to users through the foreign key {$table}.user_id → users and it has the column user_id.", $violations[$index]);
            }
        }
    }

    /**
     * @return list<string> The tables of the registry the application really uses.
     */
    private function registeredTables(): array
    {
        $registry = (new ContainerFactory())->create($this->settings())->get(UserDataRegistry::class);
        self::assertInstanceOf(UserDataRegistry::class, $registry);

        return $registry->tables();
    }
}
