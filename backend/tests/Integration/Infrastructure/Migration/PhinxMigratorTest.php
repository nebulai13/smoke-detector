<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Migration;

use PDO;
use SmokeDetector\Infrastructure\Database\LazyPdoDatabase;
use SmokeDetector\Infrastructure\Database\PdoConnectionFactory;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Migration\PhinxMigrator;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestDatabase;

/**
 * Migrations build the whole schema from nothing and can be run again at any time.
 *
 * The deploy runs them before the new release goes live (HLD §8), every time, so "nothing to do"
 * must be a normal, silent outcome.
 */
final class PhinxMigratorTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // Some tests here drop tables on purpose. Whatever happens in them, the next test finds the full schema.
        TestDatabase::migrate();
    }

    public function test_the_test_schema_was_built_by_running_every_migration(): void
    {
        self::assertNotSame([], $this->migrationFiles());
        self::assertSame(array_keys($this->migrationFiles()), $this->appliedVersions());
    }

    public function test_migrating_an_up_to_date_schema_does_nothing(): void
    {
        $tablesBefore = $this->tables();
        $versionsBefore = $this->appliedVersions();

        $applied = $this->migrator()->migrate();

        self::assertSame([], $applied);
        self::assertSame($tablesBefore, $this->tables());
        self::assertSame($versionsBefore, $this->appliedVersions());
    }

    public function test_an_empty_schema_gets_every_migration_in_order_and_a_second_run_does_nothing(): void
    {
        foreach ($this->tables() as $table) {
            // Names come from information_schema. A table name cannot be bound as a parameter.
            $this->pdo()->exec("DROP TABLE `{$table}`");
        }
        self::assertSame([], $this->tables());

        $applied = $this->migrator()->migrate();

        self::assertSame(array_values($this->migrationFiles()), $applied);
        self::assertContains('rate_limits', $this->tables());
        self::assertSame(array_keys($this->migrationFiles()), $this->appliedVersions());
        self::assertSame([], $this->migrator()->migrate());
    }

    public function test_migrations_run_on_a_connection_from_the_factory(): void
    {
        // Otherwise a migration would run with the unsafe server defaults (latin1 schema default, CET).
        $database = new LazyPdoDatabase(new PdoConnectionFactory($this->settings()->database));
        $migrator = new PhinxMigrator($database, $this->settings()->database->name, Paths::migrations());

        $migrator->migrate();

        self::assertSame('+00:00', (new Sql($database))->value('SELECT @@SESSION.time_zone'));
        self::assertSame(PDO::FETCH_ASSOC, $database->pdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
    }

    private function migrator(): PhinxMigrator
    {
        return new PhinxMigrator(
            new LazyPdoDatabase(new PdoConnectionFactory($this->settings()->database)),
            $this->settings()->database->name,
            Paths::migrations(),
        );
    }

    /**
     * @return array<int, string> Version => "version ClassName", in the order the migrations run.
     */
    private function migrationFiles(): array
    {
        $migrations = [];
        foreach (glob(Paths::migrations() . '/*.php') ?: [] as $file) {
            self::assertSame(1, preg_match('/\A(\d{14})_([a-z0-9_]+)\.php\z/', basename($file), $match));
            $class = str_replace('_', '', ucwords($match[2] ?? '', '_'));
            $migrations[(int) ($match[1] ?? 0)] = ($match[1] ?? '') . ' ' . $class;
        }
        ksort($migrations);

        return $migrations;
    }

    /**
     * @return list<mixed>
     */
    private function appliedVersions(): array
    {
        return $this->sql()->column('SELECT version FROM phinxlog ORDER BY version');
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = [];
        $names = $this->sql()->column(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
        );
        foreach ($names as $table) {
            self::assertIsString($table);
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_]+\z/', $table);
            $tables[] = $table;
        }

        return $tables;
    }
}
