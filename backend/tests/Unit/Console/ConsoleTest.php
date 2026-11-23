<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Console;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Console\Command;
use SmokeDetector\Console\Console;
use SmokeDetector\Console\MigrateCommand;
use SmokeDetector\Console\Output;
use SmokeDetector\Console\PurgeCommand;
use SmokeDetector\Application\Housekeeping\PurgeExpiredData;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Infrastructure\Migration\Migrator;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\InMemory\InMemoryMailOutbox;
use SmokeDetector\Tests\Support\InMemory\InMemoryOneTimeTokenRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryRateLimitCounters;
use SmokeDetector\Tests\Support\InMemory\InMemoryRefreshTokenRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\InMemory\InMemoryUserRepository;
use SmokeDetector\Tests\Support\TestData;

/**
 * bin/console: a command name, its arguments, an exit code. The deploy script relies on the exit code.
 */
final class ConsoleTest extends TestCase
{
    public function test_the_named_command_runs_with_the_remaining_arguments_and_decides_the_exit_code(): void
    {
        $command = self::command('cron:run', 3);
        $output = new BufferedOutput();

        $exitCode = (new Console($command))->run(['cron:run', '--now', '2026-11-17'], $output);

        self::assertSame(3, $exitCode);
        self::assertSame(['cron:run got: --now 2026-11-17'], $output->lines);
        self::assertSame([], $output->errors);
    }

    public function test_without_a_command_the_available_commands_are_listed(): void
    {
        $output = new BufferedOutput();

        $exitCode = (new Console(self::command('migrate', 0), self::command('cron:run', 0)))->run([], $output);

        self::assertSame(0, $exitCode);
        self::assertSame(
            [
                'Usage: php bin/console <command>',
                '',
                'Commands:',
                '  cron:run  Describes cron:run.',
                '  migrate   Describes migrate.',
            ],
            $output->lines,
        );
    }

    public function test_an_unknown_command_fails(): void
    {
        $output = new BufferedOutput();

        $exitCode = (new Console(self::command('migrate', 0)))->run(['migrat'], $output);

        // Not 0: a deploy script with a typo must stop.
        self::assertSame(1, $exitCode);
        self::assertSame(['Unknown command "migrat". Run php bin/console without a command for the list.'], $output->errors);
        self::assertSame([], $output->lines);
    }

    public function test_a_failing_command_is_reported_and_gives_exit_code_1(): void
    {
        $failing = new class () implements Command {
            public function name(): string
            {
                return 'explode';
            }

            public function description(): string
            {
                return 'Fails.';
            }

            public function run(array $arguments, Output $output): int
            {
                throw new RuntimeException('Cannot connect to the database.');
            }
        };
        $output = new BufferedOutput();

        $exitCode = (new Console($failing))->run(['explode'], $output);

        self::assertSame(1, $exitCode);
        self::assertSame(['explode failed: RuntimeException: Cannot connect to the database.'], $output->errors);
    }

    public function test_two_commands_cannot_share_a_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There are two console commands named "migrate".');

        new Console(self::command('migrate', 0), self::command('migrate', 0));
    }

    public function test_migrate_reports_every_migration_it_ran(): void
    {
        $output = new BufferedOutput();
        $command = new MigrateCommand(self::migrator(['20261117220000 CreateRateLimits', '20261201090000 CreateUsers']));

        $exitCode = $command->run([], $output);

        self::assertSame(0, $exitCode);
        self::assertSame(
            ['Migrated 20261117220000 CreateRateLimits', 'Migrated 20261201090000 CreateUsers', '2 migrations run.'],
            $output->lines,
        );
        self::assertSame('migrate', $command->name());
    }

    public function test_migrate_on_an_up_to_date_schema_succeeds_and_says_so(): void
    {
        $output = new BufferedOutput();

        $exitCode = (new MigrateCommand(self::migrator([])))->run([], $output);

        self::assertSame(0, $exitCode);
        self::assertSame(['Nothing to migrate.'], $output->lines);
    }

    public function test_FR_ACC_03_the_purge_command_runs_the_cleanup_and_reports_what_it_deleted(): void
    {
        // The command is what the cron job calls. Without it nothing would ever delete a
        // registration that was not confirmed within 24 hours.
        $clock = FrozenClock::at('2026-11-17 12:00:00');
        $transactions = new InMemoryTransactionRunner();
        $users = new InMemoryUserRepository($transactions);
        $counters = new InMemoryRateLimitCounters($transactions);
        $users->add(TestData::user(1, verifiedAt: null, createdAt: '2026-11-16 11:59:59'));
        $users->add(TestData::user(2, verifiedAt: null, createdAt: '2026-11-16 12:00:00'));
        $users->add(TestData::user(3, createdAt: '2026-11-01 12:00:00'));
        $counters->increment('old', TestData::time('2026-11-10 00:00:00'));
        $command = new PurgeCommand(new PurgeExpiredData(
            $transactions,
            $clock,
            $users,
            new InMemoryOneTimeTokenRepository($transactions),
            new InMemoryRefreshTokenRepository($transactions),
            $counters,
            new InMemoryMailOutbox($transactions),
        ));
        $output = new BufferedOutput();

        $exitCode = $command->run([], $output);

        self::assertSame(0, $exitCode);
        self::assertSame(
            ['Deleted 1 unconfirmed registration, 0 mail link tokens, 0 sessions, 1 rate limit counter and 0 queued mails.'],
            $output->lines,
        );
        self::assertSame([], $output->errors);
        self::assertSame('housekeeping:purge', $command->name());
        self::assertNotSame('', $command->description());
        self::assertCount(2, $users->all());

        // It can run as often as wanted.
        $again = new BufferedOutput();
        self::assertSame(0, $command->run([], $again));
        self::assertSame(['Deleted 0 unconfirmed registrations, 0 mail link tokens, 0 sessions, 0 rate limit counters and 0 queued mails.'], $again->lines);
    }

    public function test_the_purge_command_takes_no_arguments(): void
    {
        $transactions = new InMemoryTransactionRunner();
        $users = new InMemoryUserRepository($transactions);
        $users->add(TestData::user(1, verifiedAt: null, createdAt: '2026-11-01 00:00:00'));
        $command = new PurgeCommand(new PurgeExpiredData(
            $transactions,
            FrozenClock::at('2026-11-17 12:00:00'),
            $users,
            new InMemoryOneTimeTokenRepository($transactions),
            new InMemoryRefreshTokenRepository($transactions),
            new InMemoryRateLimitCounters($transactions),
            new InMemoryMailOutbox($transactions),
        ));
        $output = new BufferedOutput();

        self::assertSame(1, $command->run(['--dry-run'], $output));

        self::assertSame(['housekeeping:purge takes no arguments.'], $output->errors);
        self::assertCount(1, $users->all());
    }

    public function test_migrate_takes_no_arguments(): void
    {
        // "migrate --dry-run" must not migrate for real because the option was ignored.
        $migrator = self::migrator(['20261117220000 CreateRateLimits']);
        $output = new BufferedOutput();

        $exitCode = (new MigrateCommand($migrator))->run(['--dry-run'], $output);

        self::assertSame(1, $exitCode);
        self::assertSame(['migrate takes no arguments.'], $output->errors);
        self::assertSame(0, $migrator->runs);
    }

    private static function command(string $name, int $exitCode): Command
    {
        return new readonly class ($name, $exitCode) implements Command {
            public function __construct(private string $name, private int $exitCode)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return "Describes {$this->name}.";
            }

            public function run(array $arguments, Output $output): int
            {
                $output->line($this->name . ' got: ' . implode(' ', $arguments));

                return $this->exitCode;
            }
        };
    }

    /**
     * @param list<string> $applied
     *
     * @return Migrator&object{runs: int}
     */
    private static function migrator(array $applied): Migrator
    {
        return new class ($applied) implements Migrator {
            public int $runs = 0;

            /**
             * @param list<string> $applied
             */
            public function __construct(private readonly array $applied)
            {
            }

            public function migrate(): array
            {
                $this->runs++;

                return $this->applied;
            }
        };
    }
}
