<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Console;

use SmokeDetector\Application\Mail\MailValuesCipher;
use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Support\TestDatabase;

/**
 * bin/console as the deploy calls it, and bin/cron as the hosting's cron calls it: a real PHP
 * process, judged by its exit code (HLD §8).
 *
 * The child process inherits the environment of the test run, so it works on the test schema.
 */
final class ConsoleScriptTest extends IntegrationTestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        $this->logDirectory = sys_get_temp_dir() . '/smoke-detector-cron-test-' . bin2hex(random_bytes(6));
        mkdir($this->logDirectory);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->logDirectory) ?: [], ['.', '..']) as $name) {
            unlink($this->logDirectory . '/' . $name);
        }
        rmdir($this->logDirectory);

        // Some tests here drop tables on purpose. Whatever happens in them, the next test finds the full schema.
        TestDatabase::migrate();
    }

    public function test_migrate_succeeds_on_the_already_migrated_schema(): void
    {
        [$exitCode, $stdout, $stderr] = $this->console(['migrate']);

        self::assertSame('', $stderr);
        self::assertSame("Nothing to migrate.\n", $stdout);
        self::assertSame(0, $exitCode);
    }

    public function test_FR_ACC_03_the_cleanup_can_be_called_as_one_command_as_the_cron_job_will(): void
    {
        [$exitCode, $stdout, $stderr] = $this->console(['housekeeping:purge']);

        self::assertSame('', $stderr);
        self::assertSame("Deleted 0 unconfirmed registrations, 0 mail link tokens, 0 sessions, 0 rate limit counters and 0 queued mails.\n", $stdout);
        self::assertSame(0, $exitCode);
    }

    public function test_the_mail_outbox_can_be_sent_as_one_command(): void
    {
        [$exitCode, $stdout, $stderr] = $this->console(['mail:send']);

        self::assertSame('', $stderr);
        self::assertSame("Sent 0 mails, kept 0 mails for another try, gave up 0 mails.\n", $stdout);
        self::assertSame(0, $exitCode);
    }

    public function test_three_senders_at_the_same_moment_send_every_mail_exactly_once_and_none_fails(): void
    {
        // Real processes with connections of their own, as when a cron run is still at it while the
        // next one starts. 60 mails wait. Each is handed to the mail transport ("null://null", which
        // takes everything) by exactly one of the runs: together they report 60, not more.
        $container = (new ContainerFactory())->create($this->settings());
        $cipher = $container->get(MailValuesCipher::class);
        self::assertInstanceOf(MailValuesCipher::class, $cipher);
        $now = gmdate('Y-m-d H:i:s');
        for ($i = 1; $i <= 60; $i++) {
            $this->sql()->execute(
                'INSERT INTO users (id, email, password_hash, display_name, avatar_key, locale, timezone, email_verified_at, created_at) '
                . "VALUES (?, ?, 'x', 'User', 'avatar-01', 'de', 'Europe/Berlin', NULL, ?)",
                [TestData::uuid($i), "user{$i}@example.org", $now],
            );
            $this->sql()->execute(
                'INSERT INTO mail_outbox (id, user_id, template, locale, recipient, sealed_values, created_at, not_before, attempts) '
                . "VALUES (?, ?, 'confirm_email', 'de', ?, ?, ?, ?, 0)",
                [TestData::uuid(1000 + $i), TestData::uuid($i), "user{$i}@example.org", $cipher->seal(['confirmUrl' => "https://app.example.test/verify-email?token=t{$i}"]), $now, $now],
            );
        }

        $processes = [];
        $pipes = [];
        foreach ([0, 1, 2] as $index) {
            $process = proc_open(
                [PHP_BINARY, Paths::backend() . '/bin/console', 'mail:send'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$index],
                Paths::backend(),
                getenv(),
            );
            self::assertIsResource($process);
            $processes[$index] = $process;
        }

        $sent = 0;
        foreach ($processes as $index => $process) {
            self::assertIsResource($pipes[$index][1] ?? null);
            self::assertIsResource($pipes[$index][2] ?? null);
            $stdout = (string) stream_get_contents($pipes[$index][1]);
            $stderr = (string) stream_get_contents($pipes[$index][2]);
            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);

            // No run gives up or fails because of the others (a deadlock would show here).
            self::assertSame(['', 0], [$stderr, proc_close($process)], $stdout);
            self::assertSame(1, preg_match('/\ASent (\d+) mails?, kept 0 mails for another try, gave up 0 mails\.\n\z/', $stdout, $match), $stdout);
            $sent += (int) ($match[1] ?? 0);
        }

        self::assertSame(60, $sent);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));
    }

    public function test_cron_run_does_both_and_prints_one_line(): void
    {
        [$exitCode, $stdout, $stderr] = $this->console(['cron:run']);

        self::assertSame('', $stderr);
        self::assertSame(
            'mail:send: Sent 0 mails, kept 0 mails for another try, gave up 0 mails. | '
            . 'days:finalize: Finalized 0 days, calculated 0 baselines. | '
            . 'competitions:finalize: Expired 0 duel proposals, decided 0 duels, ranked 0 league results. | '
            . "housekeeping:purge: Deleted 0 unconfirmed registrations, 0 mail link tokens, 0 sessions, 0 rate limit counters and 0 queued mails.\n",
            $stdout,
        );
        self::assertSame(0, $exitCode);
    }

    public function test_the_cron_wrapper_is_one_executable_file_that_names_php_8_4_itself(): void
    {
        // The hosting's cron takes one path and nothing else: no interpreter, no arguments (HLD §8).
        // And plain "php" is 8.3 on that server.
        $wrapper = Paths::backend() . '/bin/cron';

        self::assertFileExists($wrapper);
        self::assertTrue(is_executable($wrapper), 'bin/cron must be executable.');
        self::assertSame(0o111, fileperms($wrapper) & 0o111, 'bin/cron must be executable for everybody.');
        $source = (string) file_get_contents($wrapper);
        self::assertStringStartsWith("#!/usr/bin/php8.4\n<?php\n", $source);
        // Cron starts it in another directory with a minimal PATH: every path comes from its own place.
        self::assertStringContainsString("require dirname(__DIR__) . '/vendor/autoload.php';", $source);
        self::assertDoesNotMatchRegularExpression('/\bargv\b|getcwd|chdir/', $source);
    }

    public function test_the_cron_wrapper_runs_the_cron_job_silently_and_writes_its_one_line_to_the_log_with_a_utc_time(): void
    {
        $before = gmdate('Y-m-d\TH:i:s');

        [$exitCode, $stdout, $stderr] = $this->cron(phpOptions: ['-d', 'date.timezone=Asia/Tokyo']);

        self::assertSame(['', '', 0], [$stdout, $stderr, $exitCode]);
        $entries = $this->logEntries();
        self::assertCount(1, $entries);
        self::assertSame('info', $entries[0]['level']);
        self::assertSame(
            'cron:run: mail:send: Sent 0 mails, kept 0 mails for another try, gave up 0 mails. | '
            . 'days:finalize: Finalized 0 days, calculated 0 baselines. | '
            . 'competitions:finalize: Expired 0 duel proposals, decided 0 duels, ranked 0 league results. | '
            . 'housekeeping:purge: Deleted 0 unconfirmed registrations, 0 mail link tokens, 0 sessions, 0 rate limit counters and 0 queued mails.',
            $entries[0]['message'],
        );
        // UTC, whatever the time zone of the server's PHP is.
        self::assertIsString($entries[0]['time']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/', $entries[0]['time']);
        self::assertGreaterThanOrEqual($before, substr($entries[0]['time'], 0, 19));
        self::assertLessThanOrEqual(gmdate('Y-m-d\TH:i:s'), substr($entries[0]['time'], 0, 19));
    }

    public function test_a_successful_cron_run_leaves_the_heartbeat_that_health_reports(): void
    {
        $before = gmdate('Y-m-d\TH:i:s\Z');

        [$exitCode] = $this->cron();

        self::assertSame(0, $exitCode);
        $heartbeat = trim((string) file_get_contents($this->logDirectory . '/cron-heartbeat'));
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $heartbeat);
        self::assertGreaterThanOrEqual($before, $heartbeat);
        self::assertLessThanOrEqual(gmdate('Y-m-d\TH:i:s\Z'), $heartbeat);
    }

    public function test_the_cron_wrapper_takes_its_command_from_nobody(): void
    {
        // Whatever is appended to the command line: it runs the cron job and nothing else.
        [$exitCode, $stdout, $stderr] = $this->cron(arguments: ['migrate', '--now', '2020-01-01']);

        self::assertSame(['', '', 0], [$stdout, $stderr, $exitCode]);
        $message = $this->logEntries()[0]['message'] ?? null;
        self::assertIsString($message);
        self::assertStringStartsWith('cron:run: mail:send: ', $message);
    }

    public function test_a_cron_run_without_the_database_fails_logs_one_line_and_leaves_no_heartbeat(): void
    {
        [$exitCode, $stdout, $stderr] = $this->cron(['DB_PORT' => '1']);

        self::assertSame(1, $exitCode);
        self::assertSame(['', ''], [$stdout, $stderr]);
        $entries = $this->logEntries();
        self::assertCount(1, $entries);
        self::assertSame('error', $entries[0]['level']);
        // Every step was tried, and each failed on its own.
        self::assertSame(
            'cron:run: mail:send: FAILED (SmokeDetector\\Infrastructure\\Database\\DatabaseConnectionFailed) | '
            . 'days:finalize: FAILED (SmokeDetector\\Infrastructure\\Database\\DatabaseConnectionFailed) | '
            . 'competitions:finalize: FAILED (SmokeDetector\\Infrastructure\\Database\\DatabaseConnectionFailed) | '
            . 'housekeeping:purge: FAILED (SmokeDetector\\Infrastructure\\Database\\DatabaseConnectionFailed)',
            $entries[0]['message'],
        );
        self::assertFileDoesNotExist($this->logDirectory . '/cron-heartbeat');
        self::assertStringNotContainsString($this->settings()->database->password->reveal(), (string) file_get_contents($this->logDirectory . '/app.log'));
    }

    public function test_a_cron_run_with_invalid_settings_fails_on_stderr_because_there_is_no_log_to_write_to(): void
    {
        [$exitCode, $stdout, $stderr] = $this->cron(['JWT_SECRET' => 'LEAK-short']);

        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('JWT_SECRET', $stderr);
        self::assertStringNotContainsString('LEAK', $stderr);
        self::assertFileDoesNotExist($this->logDirectory . '/app.log');
    }

    public function test_migrate_builds_an_empty_schema(): void
    {
        $this->sql()->execute('DROP TABLE rate_limits');
        $this->sql()->execute('DROP TABLE phinxlog');

        [$exitCode, $stdout, $stderr] = $this->console(['migrate']);

        self::assertSame('', $stderr);
        self::assertStringContainsString('Migrated 20261117220000 CreateRateLimits', $stdout);
        self::assertSame(0, $exitCode);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM rate_limits'));
    }

    public function test_migrations_are_logged_in_utc_whatever_the_time_zone_of_php_is(): void
    {
        // The hosting server's PHP runs in CET. The process switches itself to UTC, so that no date
        // function anywhere (Phinx's migration log here) writes local time into the database.
        $this->sql()->execute('DROP TABLE rate_limits');
        $this->sql()->execute('DROP TABLE phinxlog');

        [$exitCode] = $this->console(['migrate'], phpOptions: ['-d', 'date.timezone=Asia/Tokyo']);

        self::assertSame(0, $exitCode);
        $secondsOff = $this->sql()->value('SELECT ABS(TIMESTAMPDIFF(SECOND, MAX(end_time), UTC_TIMESTAMP())) FROM phinxlog');
        self::assertIsInt($secondsOff);
        self::assertLessThan(120, $secondsOff, 'The migration log is not in UTC.');
    }

    public function test_without_a_command_the_commands_are_listed(): void
    {
        [$exitCode, $stdout] = $this->console([]);

        self::assertSame(0, $exitCode);
        foreach (['competitions:finalize', 'cron:run', 'days:finalize', 'housekeeping:purge', 'mail:send', 'migrate'] as $command) {
            self::assertStringContainsString("  {$command} ", $stdout);
        }
    }

    public function test_an_unknown_command_fails_with_a_message_on_stderr(): void
    {
        [$exitCode, $stdout, $stderr] = $this->console(['migrat']);

        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Unknown command "migrat"', $stderr);
    }

    public function test_invalid_settings_fail_with_the_name_of_the_setting_and_not_its_value(): void
    {
        [$exitCode, $stdout, $stderr] = $this->console(['migrate'], ['JWT_SECRET' => 'LEAK-short']);

        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('JWT_SECRET', $stderr);
        self::assertStringNotContainsString('LEAK', $stderr);
    }

    public function test_an_unreachable_database_fails_without_a_stack_trace(): void
    {
        [$exitCode, , $stderr] = $this->console(['migrate'], ['DB_PORT' => '1']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Cannot connect to the database.', $stderr);
        self::assertStringNotContainsString('#0', $stderr);
        self::assertStringNotContainsString($this->settings()->database->password->reveal(), $stderr);
    }

    /**
     * Runs bin/cron as a PHP script. (The server starts it by its first line, "#!/usr/bin/php8.4".
     * That path does not exist in the test container, and PHP skips the line.)
     *
     * @param array<string, string> $environment Variables that differ from those of the test run.
     * @param list<string>          $phpOptions  Options for the PHP binary itself.
     * @param list<string>          $arguments   What a caller might append. The wrapper ignores it.
     *
     * @return array{int, string, string} Exit code, standard output, standard error.
     */
    private function cron(array $environment = [], array $phpOptions = [], array $arguments = []): array
    {
        return $this->script('cron', $arguments, $environment + ['LOG_FILE' => $this->logDirectory . '/app.log'], $phpOptions, '/');
    }

    /**
     * @return list<array<string, mixed>> The entries of the log the cron wrapper wrote, in order.
     */
    private function logEntries(): array
    {
        $entries = [];
        foreach (file($this->logDirectory . '/app.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($entry);
            $named = [];
            foreach ($entry as $name => $value) {
                $named[(string) $name] = $value;
            }
            $entries[] = $named;
        }

        return $entries;
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment Variables that differ from those of the test run.
     * @param list<string>          $phpOptions  Options for the PHP binary itself.
     *
     * @return array{int, string, string} Exit code, standard output, standard error.
     */
    private function console(array $arguments, array $environment = [], array $phpOptions = []): array
    {
        return $this->script('console', $arguments, $environment, $phpOptions, Paths::backend());
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     * @param list<string>          $phpOptions
     *
     * @return array{int, string, string} Exit code, standard output, standard error.
     */
    private function script(string $name, array $arguments, array $environment, array $phpOptions, string $workingDirectory): array
    {
        $process = proc_open(
            [PHP_BINARY, ...$phpOptions, Paths::backend() . '/bin/' . $name, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            $environment + getenv(),
        );
        self::assertIsResource($process);
        self::assertIsResource($pipes[1] ?? null);
        self::assertIsResource($pipes[2] ?? null);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
