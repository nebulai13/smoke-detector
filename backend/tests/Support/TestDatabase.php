<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support;

use PDO;
use RuntimeException;
use SmokeDetector\Config\Environment;
use SmokeDetector\Config\Settings;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\LazyPdoDatabase;
use SmokeDetector\Infrastructure\Database\PdoConnectionFactory;
use SmokeDetector\Infrastructure\Migration\PhinxMigrator;

/**
 * The schema the integration tests run against.
 *
 * Once per test run it is dropped, re-created and migrated from nothing, so every run proves that the
 * migrations build the whole schema. Before every test all rows are deleted (see clean()).
 *
 * Static on purpose: "once per run" is process-wide state, and PHPUnit creates one test object per
 * test method. Production code has no static state.
 */
final class TestDatabase
{
    private static bool $prepared = false;

    private static ?Database $database = null;

    /** @var list<string>|null */
    private static ?array $tables = null;

    /**
     * The settings of the test run: the values of phpunit.xml plus the database server of the environment.
     */
    public static function settings(): Settings
    {
        $settings = Settings::fromEnvironment(getenv());

        // prepare() drops the schema. These two checks keep that away from any schema with real data.
        if ($settings->environment !== Environment::Testing) {
            throw new RuntimeException('Integration tests only run with APP_ENV=testing (set by phpunit.xml).');
        }
        if (!str_ends_with($settings->database->name, '_test')) {
            throw new RuntimeException('Integration tests only run against a schema whose name ends with "_test".');
        }

        return $settings;
    }

    /**
     * Drops, re-creates and migrates the test schema. Does the work once per test run.
     */
    public static function prepare(): void
    {
        if (self::$prepared) {
            return;
        }

        $settings = self::settings();
        self::recreateSchema($settings);

        self::$prepared = true;
        self::migrate();
    }

    /**
     * Runs the migrations that are missing: the same migrator, on the same kind of connection, as
     * `php bin/console migrate`. A test that drops tables on purpose calls this when it is done, so
     * that it cannot leave the schema broken for the tests after it.
     */
    public static function migrate(): void
    {
        $settings = self::settings();
        $database = new LazyPdoDatabase(new PdoConnectionFactory($settings->database));
        (new PhinxMigrator($database, $settings->database->name, Paths::migrations()))->migrate();
    }

    /**
     * The connection the tests use to arrange and inspect data. One per test run, opened by the same
     * factory as the application's connections.
     */
    public static function database(): Database
    {
        self::prepare();

        return self::$database ??= self::newDatabase();
    }

    /**
     * A connection of its own, for the code under test or for tests that need a second session.
     */
    public static function newDatabase(): Database
    {
        self::prepare();

        return new LazyPdoDatabase(new PdoConnectionFactory(self::settings()->database));
    }

    /**
     * Deletes every row of every table (except the migration log).
     *
     * DELETE instead of TRUNCATE: on an empty table it costs next to nothing, while TRUNCATE re-creates
     * the table each time. Auto-increment counters are therefore not reset: tests must not expect ids.
     */
    public static function clean(): void
    {
        $connection = self::database()->pdo();

        self::$tables ??= self::tables($connection);

        $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::$tables as $table) {
                // Table names cannot be bound. They come from information_schema and are checked in tables().
                $connection->exec("DELETE FROM `{$table}`");
            }
        } finally {
            $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * @return list<string>
     */
    private static function tables(PDO $connection): array
    {
        $statement = $connection->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME <> ? "
            . 'ORDER BY TABLE_NAME',
        );
        $statement->execute(['phinxlog']);

        $tables = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (!is_string($table) || preg_match('/\A[A-Za-z0-9_]+\z/', $table) !== 1) {
                throw new RuntimeException('Unexpected table name in the test schema.');
            }
            $tables[] = $table;
        }

        return $tables;
    }

    /**
     * Uses an administrator login of the local or CI database server, because the application's own
     * login may (rightly) not create schemas or grant privileges.
     */
    private static function recreateSchema(Settings $settings): void
    {
        $user = getenv('TEST_DB_ADMIN_USER');
        $password = getenv('TEST_DB_ADMIN_PASSWORD');
        if (!is_string($user) || $user === '' || !is_string($password) || $password === '') {
            throw new RuntimeException(
                'Integration tests need TEST_DB_ADMIN_USER and TEST_DB_ADMIN_PASSWORD: a login of the local '
                . 'test database server that may create schemas (see docker-compose.yml).',
            );
        }

        $database = $settings->database;
        $admin = new PDO(
            "mysql:host={$database->host};port={$database->port};charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10],
        );

        // The hosting server's defaults are unsafe: not strict, CET, utf8mb3 (HLD §11). The test server
        // gets the same defaults, so a connection that forgets to fix them fails the tests here
        // instead of corrupting data there. (Not persistent: gone when the server restarts.)
        $admin->exec(
            "SET GLOBAL sql_mode = '', GLOBAL time_zone = '+01:00', "
            . "GLOBAL character_set_server = 'utf8mb3', GLOBAL collation_server = 'utf8mb3_general_ci'",
        );

        // Schema and account names cannot be bound as parameters. The schema name is restricted to
        // A-Z, a-z, 0-9 and "_" by DatabaseSettings and ends with "_test" (see settings()).
        // The schema default is latin1 on purpose: a migration that forgets to declare utf8mb4 for its
        // table then creates a latin1 table, which the schema convention test reports.
        $admin->exec("DROP DATABASE IF EXISTS `{$database->name}`");
        $admin->exec("CREATE DATABASE `{$database->name}` CHARACTER SET latin1 COLLATE latin1_swedish_ci");

        $accounts = $admin->prepare('SELECT Host FROM mysql.user WHERE User = ?');
        $accounts->execute([$database->user]);
        $hosts = $accounts->fetchAll(PDO::FETCH_COLUMN);
        if ($hosts === []) {
            throw new RuntimeException('The database login of the tests (DB_USER) does not exist on the test server.');
        }
        foreach ($hosts as $host) {
            if (!is_string($host)) {
                throw new RuntimeException('Unexpected host of the test database login.');
            }
            $account = $admin->quote($database->user) . '@' . $admin->quote($host);
            $admin->exec("GRANT ALL PRIVILEGES ON `{$database->name}`.* TO {$account}");
        }
    }
}
