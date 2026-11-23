<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Migration;

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use SmokeDetector\Infrastructure\Database\Database;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Runs the Phinx migrations of backend/migrations.
 *
 * Phinx gets the application's own connection instead of opening one from a DSN. Migrations therefore
 * run in UTC, in strict mode and with utf8mb4 like everything else, and the database password is
 * never handed to Phinx.
 */
final readonly class PhinxMigrator implements Migrator
{
    private const string ENVIRONMENT = 'app';

    public function __construct(
        private Database $database,
        /** Phinx looks its own log table up by schema name. */
        private string $schema,
        private string $migrationsPath,
    ) {
    }

    public function migrate(): array
    {
        $config = new Config([
            'paths' => ['migrations' => $this->migrationsPath],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => self::ENVIRONMENT,
                self::ENVIRONMENT => [
                    'name' => $this->schema,
                    'connection' => $this->database->pdo(),
                ],
            ],
            'version_order' => 'creation',
        ]);
        // Phinx reports on a console. What was run is returned to the caller instead.
        $manager = new Manager($config, new ArrayInput([]), new NullOutput());
        $environment = $manager->getEnvironment(self::ENVIRONMENT);

        $before = $this->versions($environment->getVersions());
        $manager->migrate(self::ENVIRONMENT);
        $after = $this->versions($environment->getVersions());

        $migrations = $manager->getMigrations(self::ENVIRONMENT);
        $applied = [];
        foreach (array_diff($after, $before) as $version) {
            $migration = $migrations[$version] ?? null;
            $applied[$version] = $version . ' ' . ($migration === null ? '(unknown)' : $migration->getName());
        }
        ksort($applied);

        return array_values($applied);
    }

    /**
     * @param array<array-key, mixed> $versions The version numbers as Phinx reads them from its log table.
     *
     * @return list<int>
     */
    private function versions(array $versions): array
    {
        $numbers = [];
        foreach ($versions as $version) {
            if (is_int($version) || (is_string($version) && ctype_digit($version))) {
                $numbers[] = (int) $version;
            }
        }

        return $numbers;
    }
}
