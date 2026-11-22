<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

use SmokeDetector\Infrastructure\Migration\Migrator;

/**
 * php bin/console migrate: brings the schema up to date. The deploy runs it before the new release
 * goes live (HLD §8), so it must be safe to run at any time, any number of times.
 */
final readonly class MigrateCommand implements Command
{
    public function __construct(private Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Runs the database migrations that have not run yet.';
    }

    public function run(array $arguments, Output $output): int
    {
        // An ignored option such as --dry-run would migrate for real against the caller's intention.
        if ($arguments !== []) {
            $output->error('migrate takes no arguments.');

            return 1;
        }

        $applied = $this->migrator->migrate();
        if ($applied === []) {
            $output->line('Nothing to migrate.');

            return 0;
        }

        foreach ($applied as $migration) {
            $output->line('Migrated ' . $migration);
        }
        $output->line(count($applied) . (count($applied) === 1 ? ' migration run.' : ' migrations run.'));

        return 0;
    }
}
