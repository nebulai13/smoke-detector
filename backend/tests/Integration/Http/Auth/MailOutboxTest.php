<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Auth;

use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\App;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Housekeeping\PurgeExpiredData;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\Mailer;
use SmokeDetector\Application\Mail\MailSendReport;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\TestAccounts;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * No web request sends mail (HLD §4.5 "Mail"; FR-ACC-01, FR-ACC-03, FR-ACC-05). The mail of a
 * request waits in the `mail_outbox` table, and the cron job sends it: on the production server a
 * request cannot answer first and send afterwards, so the time a mail server takes would tell
 * whether a mail was sent at all, which is whether an address has an account.
 *
 * Here the cron job is held back (holdMail()), so the outbox can be looked at, and then run by hand.
 */
final class MailOutboxTest extends HttpTestCase
{
    /**
     * @param App<ContainerInterface> $app
     */
    protected function routes(App $app): void
    {
        $json = $this->service(JsonResponder::class);
        $mailer = $this->service(Mailer::class);
        $transport = $this->service(TransportInterface::class);

        // What no real route does: a request that hands a mail to the mailer, and one that goes
        // around the mailer to the SMTP transport. For the tests of the harness itself.
        $app->get('/_test/send-mail', function () use ($json, $mailer): ResponseInterface {
            try {
                $mailer->send(new Mail(EmailAddress::fromInput('anna@example.org'), 'confirm_email', 'S', 'T', '<p>T</p>'));
            } catch (LogicException) {
                // Swallowed, as code that sends "after the response" would: the answer looks fine.
            }

            return $json->json(['status' => 'ok']);
        });
        $app->get('/_test/smtp', function () use ($json, $transport): ResponseInterface {
            try {
                $transport->send((new Email())->from('a@example.org')->to('b@example.org')->text('T'));
            } catch (LogicException) {
            }

            return $json->json(['status' => 'ok']);
        });
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function requestsThatMail(): iterable
    {
        yield 'a registration: the confirmation link' => ['register', 'confirm_email', 202];
        yield 'a registration with the address of an account: the note that it exists' => ['register-existing', 'already_registered', 202];
        yield 'a request for a new confirmation link' => ['resend', 'confirm_email', 202];
        yield 'a request for a new confirmation link for an account: the note that it exists' => ['resend-existing', 'already_registered', 202];
        yield 'a password reset request: the reset link' => ['reset', 'reset_password', 202];
        yield 'a login with the right password before the address is confirmed: a confirmation link' => ['login-unconfirmed', 'confirm_email', 401];
    }

    #[DataProvider('requestsThatMail')]
    public function test_FR_ACC_01_the_mail_of_a_request_waits_in_the_outbox_and_goes_out_when_the_cron_job_runs(string $flow, string $template, int $status): void
    {
        $this->holdMail();

        $response = $this->request($flow);

        // The request is answered, and nothing has been sent.
        self::assertSame($status, $response->status());
        self::assertSame([], $this->mails()->sent());
        $accountId = $this->sql()->value('SELECT id FROM users WHERE email = ?', ['anna@example.org']);
        self::assertIsString($accountId);
        $queued = $this->sql()->rows('SELECT * FROM mail_outbox');
        self::assertCount(1, $queued);
        self::assertIsString($queued[0]['id']);
        self::assertIsString($queued[0]['sealed_values']);
        self::assertSame(
            [
                'id' => $queued[0]['id'],
                'user_id' => $accountId,
                'template' => $template,
                'locale' => 'de',
                'recipient' => 'anna@example.org',
                'sealed_values' => $queued[0]['sealed_values'],
                // Queued by the request, due at once, never tried.
                'created_at' => '2026-11-17 12:00:00',
                'not_before' => '2026-11-17 12:00:00',
                'attempts' => 0,
            ],
            $queued[0],
        );
        $stored = json_encode($queued, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . base64_decode($queued[0]['sealed_values'], true);
        self::assertStringNotContainsString('http', $stored);

        // A minute later the cron job runs.
        $this->clock()->advanceSeconds(60);
        $report = $this->deliverMail();

        self::assertEquals(new MailSendReport(1, 0, 0), $report);
        $mail = $this->mailTo('anna@example.org');
        self::assertSame($template, $mail->template);
        self::assertCount(1, $this->mails()->sent());
        // Handed over, so the outbox has let go of it.
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));
        self::assertSame([], $this->logs()->records);

        if ($template !== 'already_registered') {
            // The link of the mail carries the token whose hash is stored, and the outbox never
            // held that token in a form anybody could read.
            $token = self::tokenIn($mail);
            self::assertSame([hash('sha256', $token)], $this->sql()->column('SELECT token_hash FROM one_time_tokens'));
            self::assertStringNotContainsString($token, $stored);
        }
    }

    public function test_FR_ACC_03_the_link_of_a_mail_that_was_sent_by_the_cron_job_confirms_the_registration(): void
    {
        $this->holdMail();
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $this->clock()->advanceSeconds(90);
        $this->deliverMail();

        $this->confirmEmail(self::tokenIn($this->mailTo('anna@example.org')));

        $this->logIn();
    }

    public function test_FR_ACC_05_a_reset_request_is_answered_alike_for_every_address_and_queues_a_mail_for_an_account_only(): void
    {
        $this->holdMail();
        $this->givenUser('confirmed@example.org');
        $this->givenUser('unconfirmed@example.org', verified: false);

        $confirmed = $this->call('POST', '/auth/password-reset/request', ['email' => 'confirmed@example.org']);
        $unconfirmed = $this->call('POST', '/auth/password-reset/request', ['email' => 'unconfirmed@example.org']);
        $unknown = $this->call('POST', '/auth/password-reset/request', ['email' => 'nobody@example.org']);

        // Status, every header and the body: the answer does not say whether a mail was queued.
        self::assertSame(self::everythingAClientSees($confirmed), self::everythingAClientSees($unknown));
        self::assertSame(self::everythingAClientSees($confirmed), self::everythingAClientSees($unconfirmed));
        self::assertSame(['confirmed@example.org'], $this->sql()->column('SELECT recipient FROM mail_outbox'));
    }

    public function test_FR_ACC_01_a_registration_is_answered_alike_and_queues_exactly_one_mail_whatever_the_state_of_the_address(): void
    {
        $this->holdMail();
        $this->givenUser('confirmed@example.org');
        $this->givenUser('unconfirmed@example.org', verified: false);

        $answers = [];
        foreach (['new@example.org', 'confirmed@example.org', 'unconfirmed@example.org'] as $index => $address) {
            // Each from a client of its own: none is near a limit.
            $this->fromClientAddress("198.51.100.{$index}");
            $answers[] = self::everythingAClientSees($this->call('POST', '/auth/register', TestAccounts::registration(['email' => $address])));
        }

        self::assertSame($answers[0], $answers[1]);
        self::assertSame($answers[0], $answers[2]);
        // The same work in each case: one row for the cron job. What the mail says is the difference.
        self::assertSame(
            [
                ['recipient' => 'confirmed@example.org', 'template' => 'already_registered'],
                ['recipient' => 'new@example.org', 'template' => 'confirm_email'],
                ['recipient' => 'unconfirmed@example.org', 'template' => 'confirm_email'],
            ],
            $this->sql()->rows('SELECT recipient, template FROM mail_outbox ORDER BY recipient'),
        );
    }

    public function test_NFR_05_a_mail_the_address_has_no_budget_for_is_not_queued_either(): void
    {
        $this->holdMail();
        $this->givenUser();
        for ($i = 1; $i <= 10; $i++) {
            $this->fromClientAddress("198.51.100.{$i}");
            $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);
        }
        self::assertSame(10, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));

        $this->fromClientAddress('198.51.100.11');
        $eleventh = $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);

        // The budget is what it was before the outbox: ten mails an hour, the eleventh request is
        // answered like the others, and nothing is queued or stored for it.
        self::assertSame(202, $eleventh->status());
        self::assertSame(10, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));
        self::assertEquals(new MailSendReport(10, 0, 0), $this->deliverMail());
        self::assertCount(10, $this->mails()->sent());
    }

    public function test_a_mail_server_that_is_down_delays_the_mail_and_shows_in_no_response(): void
    {
        $this->holdMail();
        $this->givenUser();
        $this->mailServer()->failure = new RuntimeException('Connection refused for anna@example.org');

        $whileDown = $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);
        $report = $this->deliverMail();

        self::assertEquals(new MailSendReport(0, 1, 0), $report);
        self::assertSame(
            [['attempts' => 1, 'not_before' => '2026-11-17 12:01:00']],
            $this->sql()->rows('SELECT attempts, not_before FROM mail_outbox'),
        );
        self::assertSame([], $this->logs()->records);

        // The mail server is back. The run that finds the mail due again sends it, with the same link.
        $this->mailServer()->failure = null;
        $this->clock()->advanceSeconds(59);
        self::assertEquals(new MailSendReport(0, 0, 0), $this->deliverMail());
        $this->clock()->advanceSeconds(1);
        self::assertEquals(new MailSendReport(1, 0, 0), $this->deliverMail());
        $token = self::tokenIn($this->mailTo('anna@example.org'));
        self::assertSame(200, $this->call('POST', '/auth/password-reset/confirm', ['token' => $token, 'password' => 'a brand new password'])->status());

        // And a request while the mail server was down was answered like one while it is up.
        $this->fromClientAddress('198.51.100.9');
        $whileUp = $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);
        self::assertSame(self::everythingAClientSees($whileUp), self::everythingAClientSees($whileDown));
    }

    public function test_FR_ACC_05_a_reset_mail_that_could_not_be_sent_within_its_hour_is_dropped_and_logged_without_the_address(): void
    {
        $this->holdMail();
        $this->givenUser();
        $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);

        // The cron job did not run for an hour (or the mail server was down that long).
        $this->clock()->advanceSeconds(3600);
        $report = $this->deliverMail();

        self::assertEquals(new MailSendReport(0, 0, 1), $report);
        self::assertSame([], $this->mails()->sent());
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));
        self::assertSame(
            [['level' => 'error', 'message' => 'A mail could not be sent and was deleted.', 'context' => ['template' => 'reset_password', 'exception' => null]]],
            $this->logs()->records,
        );
        self::assertStringNotContainsString('anna', $this->logs()->text());
    }

    public function test_FR_ACC_08_a_mail_that_waits_is_deleted_with_the_account_and_never_sent(): void
    {
        $this->holdMail();
        $anna = $this->givenUser();
        $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));

        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna))->status());

        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));
        self::assertEquals(new MailSendReport(0, 0, 0), $this->deliverMail());
        self::assertSame([], $this->mails()->sent());
    }

    public function test_FR_ACC_03_the_cleanup_takes_the_mail_of_a_registration_it_deletes_with_it(): void
    {
        $this->holdMail();
        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'never-confirmed@example.org']));
        $this->clock()->advanceSeconds(3600);
        $this->fromClientAddress('198.51.100.9');
        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'still-in-time@example.org']));
        self::assertSame(2, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));

        // A day and a second after the first registration, with a cron job whose mail step never
        // got anywhere: the cleanup alone leaves no mail behind for an account that is gone.
        $this->clock()->advanceSeconds(23 * 3600 + 1);
        self::assertSame(1, $this->service(PurgeExpiredData::class)->execute()->unconfirmedRegistrations);

        self::assertSame(['still-in-time@example.org'], $this->sql()->column('SELECT recipient FROM mail_outbox'));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox WHERE user_id NOT IN (SELECT id FROM users)'));
    }

    public function test_FR_ACC_07_the_export_names_a_mail_that_waits_without_its_content(): void
    {
        $this->holdMail();
        $anna = $this->givenUser();
        $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);
        $sealed = $this->sql()->value('SELECT sealed_values FROM mail_outbox');
        self::assertIsString($sealed);

        $export = $this->call('GET', '/me/export', headers: $this->authorization($anna));

        self::assertSame(
            [['template' => 'reset_password', 'createdAt' => '2026-11-17T12:00:00Z', 'attempts' => 0]],
            $export->json()['queuedMails'] ?? null,
        );
        self::assertStringNotContainsString($sealed, $export->body());
        self::assertStringNotContainsString('reset-password?token', $export->body());
    }

    public function test_a_request_that_handed_a_mail_to_the_mailer_would_fail_its_test(): void
    {
        // The guard every HTTP test runs under (HttpTestCase), shown to notice: a route that sends
        // mail itself and hides the failure answers 200 and still fails the test.
        try {
            $this->callUndocumented('GET', '/_test/send-mail');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('GET /api/v1/_test/send-mail sent mail itself instead of queuing it.', $failure->getMessage());
            self::assertSame([], $this->mails()->sent());

            return;
        }

        self::fail('The harness did not notice a request that sent mail.');
    }

    public function test_a_request_that_touched_the_smtp_transport_would_fail_its_test(): void
    {
        try {
            $this->callUndocumented('GET', '/_test/smtp');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('GET /api/v1/_test/smtp touched the SMTP transport.', $failure->getMessage());

            return;
        }

        self::fail('The harness did not notice a request that touched the SMTP transport.');
    }

    public function test_without_holding_the_mail_back_it_is_there_as_soon_as_the_request_is_answered(): void
    {
        // What every other HTTP test relies on: the harness runs the sender after a request that
        // queued a mail, and leaves the outbox alone after one that did not.
        $anna = $this->givenUser();
        $this->sql()->execute(
            'INSERT INTO mail_outbox (id, user_id, template, locale, recipient, sealed_values, created_at, not_before, attempts) '
            . "VALUES (UUID_v7(), ?, 'confirm_email', 'de', 'anna@example.org', 'unreadable', '2026-11-17 11:00:00', '2026-11-17 11:00:00', 0)",
            [$anna->id->value],
        );

        $this->call('GET', '/me', headers: $this->authorization($anna));
        self::assertSame(0, $this->sql()->value('SELECT attempts FROM mail_outbox'));

        $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);
        self::assertSame('reset_password', $this->mailTo('anna@example.org')->template);
        // The sender ran: it also took the row of this test. Nothing can read its values, so it
        // was dropped at once.
        self::assertSame([], $this->sql()->column('SELECT attempts FROM mail_outbox'));
    }

    private function request(string $flow): ApiResponse
    {
        if (in_array($flow, ['register-existing', 'resend-existing', 'reset'], true)) {
            $this->givenUser();
        }
        if (in_array($flow, ['resend', 'login-unconfirmed'], true)) {
            $this->givenUser(verified: false);
        }

        return match ($flow) {
            'register', 'register-existing' => $this->call('POST', '/auth/register', TestAccounts::registration()),
            'resend', 'resend-existing' => $this->call('POST', '/auth/resend-verification', ['email' => 'anna@example.org']),
            'reset' => $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']),
            default => $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body']),
        };
    }

    /**
     * @return array{int, string, array<array<string>>}
     */
    private static function everythingAClientSees(ApiResponse $response): array
    {
        return [$response->status(), $response->body(), $response->response->getHeaders()];
    }
}
