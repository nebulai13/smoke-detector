<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use SmokeDetector\Console\ConsoleEntryPoint;
use SmokeDetector\Http\FrontController;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * Mail in the real wiring, with the real transport of the settings: a web request queues its mail
 * and never talks to a mail server, whatever state that is in, and `mail:send` sends the queue
 * (HLD §4.5 "Mail", FR-ACC-01, FR-ACC-05).
 */
final class FrontControllerMailTest extends IntegrationTestCase
{
    /** Nothing listens on port 1 of this machine: the connection is refused at once. */
    private const string MAIL_SERVER_DOWN = 'smtp://LEAK-user:LEAK-password@127.0.0.1:1';

    /** No transport can be built from this at all. */
    private const string NO_SUCH_TRANSPORT = 'carrier-pigeon://LEAK-user:LEAK-password@example.test';

    /** @var list<string> */
    private array $reported = [];

    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/smoke-detector-mail-test-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function test_FR_ACC_01_a_registration_is_answered_and_its_mail_waits_in_the_outbox_for_the_cron_job(): void
    {
        $response = $this->register(['MAIL_DSN' => 'null://null']);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('{"status":"verification_sent"}', (string) $response->getBody());
        self::assertSame(
            [['template' => 'confirm_email', 'recipient' => 'anna@example.org', 'attempts' => 0]],
            $this->sql()->rows('SELECT template, recipient, attempts FROM mail_outbox'),
        );
        // In the same transaction as the account and the token of the link.
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox m JOIN users u ON u.id = m.user_id JOIN one_time_tokens t ON t.user_id = u.id'));
        self::assertSame([], $this->reported);
        self::assertSame('', $this->log());

        // "null://null" is a real symfony/mailer transport that accepts every message.
        [$exitCode, $output] = $this->mailSend(['MAIL_DSN' => 'null://null']);

        self::assertSame(0, $exitCode);
        self::assertSame(['Sent 1 mail, kept 0 mails for another try, gave up 0 mails.'], $output->lines);
        self::assertSame([], $output->errors);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));
        self::assertSame('', $this->log());
    }

    public function test_NFR_05_the_outbox_holds_nothing_of_the_link_in_plain_text(): void
    {
        $this->register(['MAIL_DSN' => 'null://null']);

        $row = $this->sql()->row('SELECT * FROM mail_outbox');
        self::assertIsArray($row);
        self::assertIsString($row['sealed_values']);
        // The real cipher: base64 of a nonce and the encrypted links, nothing to read.
        self::assertMatchesRegularExpression('~\A[A-Za-z0-9+/]{80,}={0,2}\z~', $row['sealed_values']);
        foreach ([json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) base64_decode($row['sealed_values'], true)] as $stored) {
            self::assertStringNotContainsString('http', $stored);
            self::assertStringNotContainsString('token', $stored);
            self::assertStringNotContainsString('verify-email', $stored);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenMailSettings(): iterable
    {
        yield 'a mail server that is down' => [self::MAIL_SERVER_DOWN];
        yield 'a transport that cannot even be built' => [self::NO_SUCH_TRANSPORT];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenMailSettings')]
    public function test_FR_ACC_01_the_request_does_not_notice_the_state_of_the_mail_server_at_all(string $dsn): void
    {
        $response = $this->register(['MAIL_DSN' => $dsn]);

        // Otherwise "500 or 202", or how long the answer takes, would tell whether the address
        // got a mail, that is whether it is new.
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('{"status":"verification_sent"}', (string) $response->getBody());
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM users'));
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox WHERE attempts = 0'));
        // Nobody tried to send anything: there is no failure to log.
        self::assertSame('', $this->log());
        self::assertSame([], $this->reported);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenMailSettings')]
    public function test_NFR_05_a_mail_that_cannot_be_sent_is_kept_for_another_try_and_nothing_about_it_leaks(string $dsn): void
    {
        $this->register(['MAIL_DSN' => 'null://null']);

        [$exitCode, $output] = $this->mailSend(['MAIL_DSN' => $dsn]);

        // Not a failed run: the mail is still there and will be tried again in a minute.
        self::assertSame(0, $exitCode);
        self::assertSame(['Sent 0 mails, kept 1 mail for another try, gave up 0 mails.'], $output->lines);
        self::assertSame([], $output->errors);
        self::assertSame(
            [['attempts' => 1, 'waits' => 1]],
            $this->sql()->rows('SELECT attempts, not_before > created_at AS waits FROM mail_outbox'),
        );
        // Neither the recipient, nor the link, nor the SMTP credentials, anywhere.
        foreach ([$this->log(), implode(' ', $output->lines)] as $text) {
            self::assertStringNotContainsString('anna', $text);
            self::assertStringNotContainsString('token', $text);
            self::assertStringNotContainsString('LEAK', $text);
        }

        // When the mail server is back, the next run that finds the mail due sends it.
        $this->sql()->execute('UPDATE mail_outbox SET not_before = created_at');
        [$exitCode, $output] = $this->mailSend(['MAIL_DSN' => 'null://null']);
        self::assertSame(0, $exitCode);
        self::assertSame(['Sent 1 mail, kept 0 mails for another try, gave up 0 mails.'], $output->lines);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));
    }

    public function test_NFR_05_a_mail_that_is_given_up_is_logged_with_its_template_and_the_class_of_the_failure_only(): void
    {
        $this->register(['MAIL_DSN' => 'null://null']);
        // Four tries have failed already, and the link has ten minutes left: the next try, 15
        // minutes after this one, would come too late for it. (A number of failed tries alone
        // never ends a mail.)
        $this->sql()->execute('UPDATE mail_outbox SET attempts = 4, created_at = created_at - INTERVAL 1430 MINUTE');

        [$exitCode, $output] = $this->mailSend(['MAIL_DSN' => self::MAIL_SERVER_DOWN]);

        self::assertSame(0, $exitCode);
        self::assertSame(['Sent 0 mails, kept 0 mails for another try, gave up 1 mail.'], $output->lines);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));
        $entries = array_values(array_filter(explode("\n", $this->log())));
        self::assertCount(1, $entries);
        $entry = json_decode($entries[0], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);
        self::assertSame('error', $entry['level'] ?? null);
        self::assertSame('A mail could not be sent and was deleted.', $entry['message'] ?? null);
        self::assertSame(
            ['template' => 'confirm_email', 'exception' => 'SmokeDetector\\Infrastructure\\Mail\\MailDeliveryFailed'],
            $entry['context'] ?? null,
        );
        self::assertStringNotContainsString('anna', $this->log());
        self::assertStringNotContainsString('LEAK', $this->log());
        self::assertStringNotContainsString('127.0.0.1', $this->log());
    }

    public function test_a_request_that_queues_no_mail_is_not_affected_by_the_mail_settings(): void
    {
        $response = $this->frontController()->handle(
            new ServerRequest('GET', '/api/v1/health'),
            ['MAIL_DSN' => self::NO_SUCH_TRANSPORT, 'LOG_FILE' => $this->logFile] + getenv(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $this->log());
        self::assertSame([], $this->reported);
    }

    public function test_mail_settings_that_cannot_work_do_not_keep_the_cleanup_from_running(): void
    {
        $output = new BufferedOutput();

        $exitCode = (new ConsoleEntryPoint())->run(
            ['housekeeping:purge'],
            ['MAIL_DSN' => self::NO_SUCH_TRANSPORT, 'LOG_FILE' => $this->logFile] + getenv(),
            $output,
        );

        self::assertSame([], $output->errors);
        self::assertSame(0, $exitCode);
    }

    /**
     * @param array<string, string> $environment
     */
    private function register(array $environment): ResponseInterface
    {
        $body = json_encode(TestAccounts::registration(), JSON_THROW_ON_ERROR);
        $request = new ServerRequest(
            'POST',
            '/api/v1/auth/register',
            ['Content-Type' => 'application/json', 'Content-Length' => (string) strlen($body)],
            $body,
            '1.1',
            ['REMOTE_ADDR' => '203.0.113.10'],
        );

        return $this->frontController()->handle($request, $environment + ['LOG_FILE' => $this->logFile] + getenv());
    }

    /**
     * Runs `php bin/console mail:send` in this process, with the real mailer of the given settings.
     *
     * @param array<string, string> $environment
     *
     * @return array{int, BufferedOutput} The exit code and what was printed.
     */
    private function mailSend(array $environment): array
    {
        $output = new BufferedOutput();
        $exitCode = (new ConsoleEntryPoint())->run(['mail:send'], $environment + ['LOG_FILE' => $this->logFile] + getenv(), $output);

        return [$exitCode, $output];
    }

    private function log(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    private function frontController(): FrontController
    {
        return new FrontController(function (string $message): void {
            $this->reported[] = $message;
        });
    }
}
