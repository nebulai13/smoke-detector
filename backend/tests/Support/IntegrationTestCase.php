<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support;

use PDO;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Config\Settings;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * Base class of every test that needs the real database.
 *
 * - The test schema is created and migrated from nothing once per test run (TestDatabase::prepare()).
 * - Every test starts with empty tables: all rows are deleted BEFORE each test. Deleting before
 *   rather than after means a failed test leaves its rows behind for inspection.
 *
 * Why deleting rows and not a transaction that is rolled back: use cases open and commit their own
 * transactions (and are tested for rolling back on failure), HTTP tests run the application with its
 * own connection, and some tests need two sessions that see each other's committed data. None of
 * that works inside one surrounding transaction.
 */
abstract class IntegrationTestCase extends TestCase
{
    #[BeforeClass]
    final public static function prepareTestDatabase(): void
    {
        TestDatabase::prepare();
    }

    #[Before]
    final protected function startWithEmptyTables(): void
    {
        TestDatabase::clean();
    }

    /**
     * The settings of the test run (APP_ENV=testing, schema smoke_detector_test).
     */
    protected function settings(): Settings
    {
        return TestDatabase::settings();
    }

    /**
     * Runs statements on the shared test connection, to arrange and inspect data:
     * $this->sql()->execute('INSERT …', [...]), $this->sql()->rows('SELECT …').
     */
    protected function sql(): Sql
    {
        return new Sql(TestDatabase::database());
    }

    /**
     * The shared test connection itself. Do not leave a transaction open on it.
     */
    protected function pdo(): PDO
    {
        return TestDatabase::database()->pdo();
    }

    /**
     * A connection of its own (opened on first use): for the code under test, or as a second session
     * that only sees what the first one committed.
     */
    protected function newDatabase(): Database
    {
        return TestDatabase::newDatabase();
    }
}
