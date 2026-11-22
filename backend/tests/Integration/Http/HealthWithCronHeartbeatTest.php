<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use SmokeDetector\Config\Settings;
use SmokeDetector\Console\Console;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\SettingsFixture;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * GET /health on an installation that logs to a file, as production does: next to that file the
 * cron job keeps its heartbeat, and health reports it (HLD §7).
 */
final class HealthWithCronHeartbeatTest extends HttpTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/smoke-detector-health-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $name) {
            unlink($this->directory . '/' . $name);
        }
        rmdir($this->directory);
    }

    protected function settings(): Settings
    {
        return SettingsFixture::modified(parent::settings(), logFile: $this->directory . '/app.log');
    }

    public function test_before_the_first_cron_run_the_heartbeat_is_null(): void
    {
        self::assertSame(
            ['status' => 'ok', 'version' => 'test-release', 'cronLastRunAt' => null],
            $this->call('GET', '/health')->json(),
        );
    }

    public function test_health_reports_when_the_cron_job_last_ran_through(): void
    {
        $this->clock()->advanceSeconds(65);
        self::assertSame(0, $this->cronRun()[0]);
        $this->clock()->advanceSeconds(20);

        $response = $this->call('GET', '/health');

        self::assertSame(200, $response->status());
        self::assertSame(
            ['status' => 'ok', 'version' => 'test-release', 'cronLastRunAt' => '2026-11-17T12:01:05+00:00'],
            $response->json(),
        );

        // Every run moves it on.
        $this->clock()->advanceSeconds(40);
        $this->cronRun();
        self::assertSame('2026-11-17T12:02:05+00:00', $this->call('GET', '/health')->json()['cronLastRunAt'] ?? null);
    }

    public function test_FR_ACC_01_the_cron_job_sends_the_mail_a_request_queued_and_says_so_in_its_one_line(): void
    {
        $this->holdMail();
        $this->call('POST', '/auth/register', TestAccounts::registration());
        self::assertSame([], $this->mails()->sent());

        // The mail server of the tests takes mail only from the cron job.
        $this->mailServer()->cronIsRunning = true;
        [$exitCode, $output] = $this->cronRun();
        $this->mailServer()->cronIsRunning = false;

        self::assertSame(0, $exitCode);
        self::assertSame(
            ['mail:send: Sent 1 mail, kept 0 mails for another try, gave up 0 mails. | '
                . 'housekeeping:purge: Deleted 0 unconfirmed registrations, 0 mail link tokens, 0 sessions, 0 rate limit counters and 0 queued mails.'],
            $output->lines,
        );
        self::assertSame('confirm_email', $this->mailTo('anna@example.org')->template);
        self::assertSame('2026-11-17T12:00:00+00:00', $this->call('GET', '/health')->json()['cronLastRunAt'] ?? null);
    }

    public function test_a_cron_run_in_which_a_step_failed_does_not_move_the_heartbeat(): void
    {
        $this->cronRun();
        $this->clock()->advanceSeconds(60);
        // A damaged row: the sender stops at it, loudly, instead of mailing something wrong.
        $anna = $this->givenUser();
        $this->sql()->execute(
            'INSERT INTO mail_outbox (id, user_id, template, locale, recipient, sealed_values, created_at, not_before, attempts) '
            . "VALUES (UUID_v7(), ?, 'confirm_email', 'de', 'not an address', 'x', '2026-11-17 12:00:00', '2026-11-17 12:00:00', 0)",
            [$anna->id->value],
        );

        [$exitCode, $output] = $this->cronRun();

        self::assertSame(1, $exitCode);
        // The cleanup ran all the same.
        self::assertStringStartsWith(
            'mail:send: FAILED (SmokeDetector\\Application\\Validation\\InvalidValue) | housekeeping:purge: Deleted 0 ',
            $output->lines[0] ?? '',
        );
        self::assertSame('2026-11-17T12:00:00+00:00', $this->call('GET', '/health')->json()['cronLastRunAt'] ?? null);
    }

    /**
     * Runs `cron:run` with the application under test: its clock, its database, its mail server.
     *
     * @return array{int, BufferedOutput} The exit code and what was printed.
     */
    private function cronRun(): array
    {
        $output = new BufferedOutput();

        return [$this->service(Console::class)->run(['cron:run'], $output), $output];
    }
}
