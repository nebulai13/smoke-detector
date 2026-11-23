<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Mail;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Application\Mail\MailSendReport;
use SmokeDetector\Application\Mail\MailValuesUnreadable;
use SmokeDetector\Application\Mail\QueuedMail;
use SmokeDetector\Application\Mail\SendQueuedMails;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Tests\Support\Fake\FakeMailValuesCipher;
use SmokeDetector\Tests\Support\Fake\ListingMailTemplates;
use SmokeDetector\Tests\Support\Fake\RecordingLogger;
use SmokeDetector\Tests\Support\Fake\ScriptedMailer;
use SmokeDetector\Tests\Support\Fake\ThrowingOnDeleteMailOutbox;
use SmokeDetector\Tests\Support\InMemory\InMemoryMailOutbox;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\TestData;

/**
 * The sender of the mail outbox, as the cron job runs it (HLD §4.5 "Mail"). The clock stands at
 * 2026-11-17 12:00:00 UTC and only moves when the mail server takes time.
 */
final class SendQueuedMailsTest extends TestCase
{
    private const string NOW = '2026-11-17 12:00:00';

    private FrozenClock $clock;
    private InMemoryTransactionRunner $transactions;
    private InMemoryMailOutbox $outbox;
    private FakeMailValuesCipher $cipher;
    private ScriptedMailer $mailer;
    private ListingMailTemplates $templates;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->clock = FrozenClock::at(self::NOW);
        $this->transactions = new InMemoryTransactionRunner();
        $this->outbox = new InMemoryMailOutbox($this->transactions);
        $this->cipher = new FakeMailValuesCipher();
        $this->mailer = new ScriptedMailer($this->clock, $this->transactions);
        $this->templates = new ListingMailTemplates();
        $this->logger = new RecordingLogger();
    }

    public function test_a_due_mail_is_rendered_from_its_template_in_its_language_sent_to_its_address_and_deleted(): void
    {
        $this->given(TestData::queuedMail(
            10,
            kind: MailKind::ConfirmEmail,
            sealedValues: $this->cipher->seal(['confirmUrl' => 'https://app.example.test/verify-email?token=the-token']),
            locale: Locale::En,
            to: 'anna@example.org',
        ));

        $report = $this->sender()->execute();

        self::assertEquals(new MailSendReport(1, 0, 0), $report);
        self::assertCount(1, $this->mailer->delivered);
        $mail = $this->mailer->delivered[0];
        self::assertSame('anna@example.org', $mail->to->value);
        self::assertSame('confirm_email', $mail->template);
        self::assertSame('confirm_email [en]', $mail->subject);
        self::assertSame('confirmUrl=https://app.example.test/verify-email?token=the-token', $mail->text);
        // Handed to the mail server, so it is gone: the outbox keeps nothing longer than needed.
        self::assertSame([], $this->outbox->all());
        self::assertSame([], $this->logger->records);
    }

    public function test_every_kind_of_mail_is_rendered_from_the_template_of_its_name(): void
    {
        $this->given(
            TestData::queuedMail(10, 1, MailKind::ResetPassword, $this->cipher->seal(['resetUrl' => 'https://app.example.test/reset-password?token=r'])),
            TestData::queuedMail(11, 2, MailKind::AlreadyRegistered, $this->cipher->seal(['loginUrl' => 'https://app.example.test/login', 'resetUrl' => 'https://app.example.test/reset-password'])),
        );

        self::assertEquals(new MailSendReport(2, 0, 0), $this->sender()->execute());

        self::assertSame(
            [
                ['user1@example.org', 'reset_password [de]', 'resetUrl=https://app.example.test/reset-password?token=r'],
                ['user2@example.org', 'already_registered [de]', "loginUrl=https://app.example.test/login\nresetUrl=https://app.example.test/reset-password"],
            ],
            array_map(static fn (Mail $mail): array => [$mail->to->value, $mail->subject, $mail->text], $this->mailer->delivered),
        );
    }

    public function test_with_nothing_to_send_a_run_looks_once_and_touches_no_mail_server(): void
    {
        $report = $this->sender()->execute();

        self::assertEquals(new MailSendReport(0, 0, 0), $report);
        self::assertSame(1, $this->outbox->claims);
        self::assertSame([], $this->mailer->attempts);
        self::assertSame([], $this->logger->records);
    }

    public function test_a_mail_is_due_from_its_not_before_time_on_and_not_a_second_earlier(): void
    {
        $this->given(
            TestData::queuedMail(10, 1, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:50:00', notBefore: '2026-11-17 12:00:01'),
            TestData::queuedMail(11, 2, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:50:00', notBefore: self::NOW),
        );

        self::assertEquals(new MailSendReport(1, 0, 0), $this->sender()->execute());
        self::assertSame(['user2@example.org'], $this->mailer->deliveredTo());
        // The one that waits is exactly as it was.
        self::assertEquals(
            [TestData::queuedMail(10, 1, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:50:00', notBefore: '2026-11-17 12:00:01')],
            $this->outbox->all(),
        );

        $this->clock->advanceSeconds(1);
        self::assertEquals(new MailSendReport(1, 0, 0), $this->sender()->execute());
        self::assertSame(['user2@example.org', 'user1@example.org'], $this->mailer->deliveredTo());
    }

    public function test_mails_go_out_oldest_first_in_batches_of_ten_until_none_is_left(): void
    {
        // Stored newest first: the order is by age, not by the order of the rows.
        for ($i = 25; $i >= 1; $i--) {
            $this->given(TestData::queuedMail(100 + $i, $i, sealedValues: $this->sealed(), createdAt: sprintf('2026-11-17 11:%02d:00', $i)));
        }
        $leasedDuringTheFirstMail = null;
        $this->mailer->whileSending = function () use (&$leasedDuringTheFirstMail): void {
            $leasedDuringTheFirstMail ??= count($this->leased());
        };

        $report = $this->sender()->execute();

        self::assertEquals(new MailSendReport(25, 0, 0), $report);
        self::assertSame(array_map(static fn (int $i): string => "user{$i}@example.org", range(1, 25)), $this->mailer->deliveredTo());
        self::assertSame(10, $leasedDuringTheFirstMail);
        // 10 + 10 + 5, and a last look that finds nothing.
        self::assertSame(4, $this->outbox->claims);
        self::assertSame([], $this->outbox->all());
    }

    public function test_a_run_takes_at_most_fifty_batches(): void
    {
        for ($i = 1; $i <= 501; $i++) {
            $this->given(TestData::queuedMail(1000 + $i, $i, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:00:00'));
        }

        $report = $this->sender()->execute();

        // A bound that does not depend on the clock: whatever happens, a run ends.
        self::assertEquals(new MailSendReport(500, 0, 0), $report);
        self::assertSame(50, $this->outbox->claims);
        self::assertEquals([TestData::queuedMail(1501, 501, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:00:00')], $this->outbox->all());
    }

    public function test_the_mail_server_is_never_talked_to_inside_a_database_transaction(): void
    {
        $this->given(TestData::queuedMail(10, 1, sealedValues: $this->sealed()));

        // ScriptedMailer refuses a call inside a transaction, and the outbox refuses to hand out
        // mails outside one (InMemoryMailOutbox::claimDue): taking mails is a short transaction of
        // its own, and it is over before the first mail is sent.
        self::assertEquals(new MailSendReport(1, 0, 0), $this->sender()->execute());
        self::assertSame(['mail_outbox', 'mail_outbox'], $this->transactions->locks);
        self::assertSame(2, $this->transactions->commits);
    }

    public function test_while_a_mail_is_being_sent_its_row_is_set_aside_for_ten_minutes_so_no_other_run_takes_it(): void
    {
        $this->given(TestData::queuedMail(10, 1, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:59:00', attempts: 2));
        $during = null;
        $this->mailer->whileSending = function () use (&$during): void {
            $during = $this->outbox->all();
        };

        $this->sender()->execute();

        // Still there (a crash now loses nothing), with its attempts as they were (a crash is not
        // a failure of the mail server), and not due again before 12:10:00.
        self::assertEquals(
            [TestData::queuedMail(10, 1, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:59:00', notBefore: '2026-11-17 12:10:00', attempts: 2)],
            $during,
        );
    }

    public function test_a_sent_mail_that_cannot_be_deleted_stays_set_aside_and_the_run_goes_on(): void
    {
        // For example a deadlock with the deletion of the account, or a database that went away.
        $this->given(
            TestData::queuedMail(10, 1, sealedValues: $this->cipher->seal(['confirmUrl' => 'https://app.example.test/verify-email?token=SECRET-TOKEN']), createdAt: '2026-11-17 11:58:00'),
            TestData::queuedMail(11, 2, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:59:00'),
        );
        $this->outbox->failNextDeletes = 1;

        $report = $this->sender()->execute();

        // Both went out, and the run did not fail: the mail server has the first mail.
        self::assertEquals(new MailSendReport(2, 0, 0), $report);
        self::assertSame(['user1@example.org', 'user2@example.org'], $this->mailer->deliveredTo());
        // Its row is still there, set aside for ten minutes, with no failed try counted.
        self::assertEquals(
            [TestData::queuedMail(10, 1, sealedValues: $this->cipher->seal(['confirmUrl' => 'https://app.example.test/verify-email?token=SECRET-TOKEN']), createdAt: '2026-11-17 11:58:00', notBefore: '2026-11-17 12:10:00')],
            $this->outbox->all(),
        );
        self::assertSame(
            [[
                'level' => 'warning',
                'message' => 'A sent mail could not be deleted and may go out a second time.',
                'context' => ['template' => 'confirm_email', 'exception' => RuntimeException::class],
            ]],
            $this->logger->records,
        );
        self::assertStringNotContainsString('user1', $this->logger->text());
        self::assertStringNotContainsString('SECRET-TOKEN', $this->logger->text());

        // Until then no run touches it. After that it goes out a second time: a duplicate, which
        // is the price for never losing one.
        $this->clock->advanceSeconds(599);
        self::assertEquals(new MailSendReport(0, 0, 0), $this->sender()->execute());
        $this->clock->advanceSeconds(1);
        self::assertEquals(new MailSendReport(1, 0, 0), $this->sender()->execute());
        self::assertSame(['user1@example.org', 'user2@example.org', 'user1@example.org'], $this->mailer->deliveredTo());
        self::assertSame([], $this->outbox->all());
    }

    public function test_a_programming_error_while_deleting_a_sent_mail_is_not_swallowed(): void
    {
        $this->given(TestData::queuedMail(10, 1, sealedValues: $this->sealed()));
        $failure = new LogicException('A repository was used the wrong way.');
        $outbox = new ThrowingOnDeleteMailOutbox($this->outbox, $failure);
        $sender = new SendQueuedMails($this->transactions, $this->clock, $outbox, $this->cipher, $this->templates, $this->mailer, $this->logger);

        try {
            $sender->execute();
            self::fail('Expected the failure to be passed on.');
        } catch (LogicException $passedOn) {
            self::assertSame($failure, $passedOn);
        }
    }

    public function test_a_mail_the_server_does_not_take_is_tried_again_a_minute_later_and_the_others_still_go_out(): void
    {
        $this->given(
            TestData::queuedMail(10, 1, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:58:00'),
            TestData::queuedMail(11, 2, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:59:00'),
        );
        $this->mailer->failures = ['user1@example.org' => null];

        $report = $this->sender()->execute();

        self::assertEquals(new MailSendReport(1, 1, 0), $report);
        self::assertSame(['user1@example.org', 'user2@example.org'], $this->mailer->attempts);
        self::assertSame(['user2@example.org'], $this->mailer->deliveredTo());
        self::assertEquals(
            [TestData::queuedMail(10, 1, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:58:00', notBefore: '2026-11-17 12:01:00', attempts: 1)],
            $this->outbox->all(),
        );
        // A failure that will be tried again is not worth a log line: the summary of the run counts it.
        self::assertSame([], $this->logger->records);
        // The same run does not try it a second time.
        self::assertSame(2, $this->outbox->claims);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function failedAttempts(): iterable
    {
        yield 'after the first failure: 1 minute' => [0, '2026-11-17 12:01:00'];
        yield 'after the second: 5 minutes' => [1, '2026-11-17 12:05:00'];
        yield 'after the third: 15 minutes' => [2, '2026-11-17 12:15:00'];
        yield 'after the fourth: 15 minutes again' => [3, '2026-11-17 12:15:00'];
        yield 'after the fifth, which used to be the last: 15 minutes' => [4, '2026-11-17 12:15:00'];
        yield 'after the fortieth: still 15 minutes' => [39, '2026-11-17 12:15:00'];
    }

    #[DataProvider('failedAttempts')]
    public function test_each_failure_makes_the_wait_for_the_next_try_longer(int $attemptsBefore, string $nextTry): void
    {
        $this->given(TestData::queuedMail(10, 1, sealedValues: $this->sealed(), createdAt: '2026-11-17 10:00:00', notBefore: self::NOW, attempts: $attemptsBefore));
        $this->mailer->failures = ['user1@example.org' => null];

        self::assertEquals(new MailSendReport(0, 1, 0), $this->sender()->execute());

        self::assertEquals(
            [TestData::queuedMail(10, 1, sealedValues: $this->sealed(), createdAt: '2026-11-17 10:00:00', notBefore: $nextTry, attempts: $attemptsBefore + 1)],
            $this->outbox->all(),
        );
        self::assertSame([], $this->logger->records);
    }

    public function test_the_wait_for_the_next_try_starts_when_the_mail_server_has_given_up(): void
    {
        $this->given(TestData::queuedMail(10, 1, sealedValues: $this->sealed()));
        $this->mailer->failures = ['user1@example.org' => null];
        // A mail server that does not answer: the failure comes after its timeout.
        $this->mailer->secondsPerMail = 30;

        $this->sender()->execute();

        self::assertEquals(TestData::time('2026-11-17 12:01:30'), $this->outbox->all()[0]->notBefore);
    }

    public function test_NFR_05_a_mail_that_is_given_up_is_deleted_and_logged_in_one_line_without_address_link_or_the_servers_words(): void
    {
        // A reset mail queued at 11:01: its link works until 12:01, and the next try would come at 12:15.
        $this->given(TestData::queuedMail(
            10,
            1,
            MailKind::ResetPassword,
            $this->cipher->seal(['resetUrl' => 'https://app.example.test/reset-password?token=SECRET-TOKEN']),
            '2026-11-17 11:01:00',
            self::NOW,
            4,
            to: 'anna@example.org',
        ));
        $this->mailer->failures = ['anna@example.org' => null];

        $report = $this->sender()->execute();

        self::assertEquals(new MailSendReport(0, 0, 1), $report);
        self::assertSame([], $this->outbox->all());
        self::assertSame(
            [[
                'level' => 'error',
                'message' => 'A mail could not be sent and was deleted.',
                'context' => ['template' => 'reset_password', 'exception' => RuntimeException::class],
            ]],
            $this->logger->records,
        );
        // ScriptedMailer's failure names the address and quotes the text of the mail, link included.
        self::assertStringNotContainsString('anna', $this->logger->text());
        self::assertStringNotContainsString('SECRET-TOKEN', $this->logger->text());
        self::assertStringNotContainsString('Cannot send', $this->logger->text());
    }

    public function test_FR_ACC_03_a_confirmation_mail_is_tried_for_as_long_as_its_link_is_valid_however_often_it_fails(): void
    {
        $this->given(TestData::queuedMail(10, 1, sealedValues: $this->sealed()));
        $this->mailer->failures = ['user1@example.org' => null];

        $tries = $this->runUntilTheOutboxIsEmpty();

        // Queued at 12:00:00, link valid for 24 hours. Tries 0 s, 60 s and 360 s after that, then
        // every 900 s from 1260 s on. The try at 85 860 s is the last: the next one would come at
        // 86 760 s, when the link is dead. That is 3 + 95 tries.
        self::assertSame(98, $tries);
        self::assertCount(98, $this->mailer->attempts);
        self::assertEquals(TestData::time('2026-11-18 11:51:00'), $this->clock->now());
        self::assertSame(
            [['level' => 'error', 'message' => 'A mail could not be sent and was deleted.', 'context' => ['template' => 'confirm_email', 'exception' => RuntimeException::class]]],
            $this->logger->records,
        );
    }

    public function test_FR_ACC_05_a_reset_mail_is_tried_six_times_within_the_hour_of_its_link(): void
    {
        $this->given(TestData::queuedMail(10, 1, MailKind::ResetPassword, $this->sealed()));
        $this->mailer->failures = ['user1@example.org' => null];

        // 12:00, 12:01, 12:06, 12:21, 12:36 and 12:51. The next try would come at 13:06.
        self::assertSame(6, $this->runUntilTheOutboxIsEmpty());
        self::assertEquals(TestData::time('2026-11-17 12:51:00'), $this->clock->now());
        self::assertCount(1, $this->logger->records);
    }

    public function test_FR_ACC_05_a_reset_mail_still_arrives_when_the_mail_server_was_down_for_forty_minutes(): void
    {
        $this->given(TestData::queuedMail(10, 1, MailKind::ResetPassword, $this->sealed()));
        $this->mailer->failures = ['user1@example.org' => null];

        for ($run = 0; $run < 20 && $this->outbox->all() !== []; $run++) {
            $due = $this->outbox->all()[0]->notBefore;
            $this->clock->advanceSeconds(max(0, $due->getTimestamp() - $this->clock->now()->getTimestamp()));
            if ($this->clock->now() >= TestData::time('2026-11-17 12:40:00')) {
                $this->mailer->failures = [];
            }
            $this->sender()->execute();
        }

        // The tries at 12:00, 12:01, 12:06, 12:21 and 12:36 failed. The one at 12:51 delivered.
        self::assertSame(['user1@example.org'], $this->mailer->deliveredTo());
        self::assertCount(6, $this->mailer->attempts);
        self::assertSame([], $this->outbox->all());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{MailKind, int}>
     */
    public static function lifetimes(): iterable
    {
        yield 'confirmation link: 24 hours' => [MailKind::ConfirmEmail, 86_400];
        yield 'note about an existing account: 24 hours' => [MailKind::AlreadyRegistered, 86_400];
        yield 'reset link: 1 hour' => [MailKind::ResetPassword, 3_600];
    }

    #[DataProvider('lifetimes')]
    public function test_a_mail_whose_link_has_expired_is_not_sent_any_more_but_deleted_and_logged(MailKind $kind, int $lifetime): void
    {
        $values = $kind === MailKind::AlreadyRegistered ? ['loginUrl' => 'https://app.example.test/login'] : ['url' => 'https://app.example.test/x?token=SECRET-TOKEN'];
        $this->given(
            TestData::queuedMail(10, 1, $kind, $this->cipher->seal($values), self::NOW),
            TestData::queuedMail(11, 2, $kind, $this->cipher->seal($values), '2026-11-17 12:00:01'),
        );

        // The first mail's link is worthless from this second on. The second mail's works one more.
        $this->clock->advanceSeconds($lifetime);
        $report = $this->sender()->execute();

        self::assertEquals(new MailSendReport(1, 0, 1), $report);
        self::assertSame(['user2@example.org'], $this->mailer->attempts);
        self::assertSame([], $this->outbox->all());
        self::assertSame(
            [[
                'level' => 'error',
                'message' => 'A mail could not be sent and was deleted.',
                // No mail server was asked this time, so there is no failure to name.
                'context' => ['template' => $kind->value, 'exception' => null],
            ]],
            $this->logger->records,
        );
        self::assertStringNotContainsString('user1', $this->logger->text());
        self::assertStringNotContainsString('SECRET-TOKEN', $this->logger->text());
    }

    public function test_the_lifetime_of_a_mail_is_the_lifetime_of_the_token_in_its_link(): void
    {
        self::assertSame(TokenPurpose::VerifyEmail->lifetimeSeconds(), MailKind::ConfirmEmail->lifetimeSeconds());
        self::assertSame(TokenPurpose::ResetPassword->lifetimeSeconds(), MailKind::ResetPassword->lifetimeSeconds());
        // No token, but it answers a sign-up or a request for a confirmation link: it is worth as
        // long as the confirmation mail it stands in for.
        self::assertSame(TokenPurpose::VerifyEmail->lifetimeSeconds(), MailKind::AlreadyRegistered->lifetimeSeconds());
        self::assertEquals(TestData::time('2026-11-17 13:00:00'), TestData::queuedMail(1, kind: MailKind::ResetPassword)->expiresAt());
        self::assertEquals(TestData::time('2026-11-18 12:00:00'), TestData::queuedMail(1, kind: MailKind::ConfirmEmail, notBefore: '2026-11-17 12:30:00')->expiresAt());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function failuresOfAResetMail(): iterable
    {
        // Queued at 12:00:00, so its link works until 13:00:00. The first retry comes a minute later.
        yield 'the retry would come one second before the link expires' => [3539, true];
        yield 'the retry would come the second the link expires' => [3540, false];
    }

    #[DataProvider('failuresOfAResetMail')]
    public function test_a_failed_mail_is_given_up_when_its_next_try_would_come_too_late_for_its_link(int $failsAfterSeconds, bool $triedAgain): void
    {
        $this->given(TestData::queuedMail(10, 1, MailKind::ResetPassword, $this->sealed(), self::NOW, '2026-11-17 12:30:00'));
        $this->mailer->failures = ['user1@example.org' => new LogicException('The mail server said: no.')];
        $this->clock->advanceSeconds($failsAfterSeconds);

        $report = $this->sender()->execute();

        if ($triedAgain) {
            self::assertEquals(new MailSendReport(0, 1, 0), $report);
            self::assertEquals(
                [TestData::queuedMail(10, 1, MailKind::ResetPassword, $this->sealed(), self::NOW, '2026-11-17 12:59:59', 1)],
                $this->outbox->all(),
            );
            self::assertSame([], $this->logger->records);
        } else {
            self::assertEquals(new MailSendReport(0, 0, 1), $report);
            self::assertSame([], $this->outbox->all());
            self::assertSame(
                [['level' => 'error', 'message' => 'A mail could not be sent and was deleted.', 'context' => ['template' => 'reset_password', 'exception' => LogicException::class]]],
                $this->logger->records,
            );
        }
    }

    public function test_NFR_05_values_that_cannot_be_opened_are_dropped_at_once_and_never_logged(): void
    {
        // For example after jwt.secret was changed: the key the values were encrypted with is gone.
        // Waiting does not bring it back, so these mails are not tried again.
        $this->given(
            TestData::queuedMail(10, 1, sealedValues: 'not-what-the-cipher-made', createdAt: '2026-11-17 11:00:00'),
            TestData::queuedMail(11, 2, MailKind::ResetPassword, 'neither-is-this', '2026-11-17 11:30:00', attempts: 4),
            TestData::queuedMail(12, 3, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:40:00'),
        );

        $report = $this->sender()->execute();

        // Dropped, and the run is not a failed run: the mail after them goes out.
        self::assertEquals(new MailSendReport(1, 0, 2), $report);
        self::assertSame(0, $report->notMade);
        self::assertSame(['user3@example.org'], $this->mailer->attempts);
        self::assertSame([], $this->outbox->all());
        self::assertSame(
            [
                ['level' => 'error', 'message' => 'A mail could not be sent and was deleted.', 'context' => ['template' => 'confirm_email', 'exception' => MailValuesUnreadable::class]],
                ['level' => 'error', 'message' => 'A mail could not be sent and was deleted.', 'context' => ['template' => 'reset_password', 'exception' => MailValuesUnreadable::class]],
            ],
            $this->logger->records,
        );
        self::assertStringNotContainsString('not-what-the-cipher-made', $this->logger->text());
        self::assertStringNotContainsString('neither-is-this', $this->logger->text());
    }

    public function test_a_mail_whose_template_cannot_be_rendered_is_kept_is_no_failed_try_and_fails_the_run(): void
    {
        // A release that was uploaded without one of its templates. The mail server is not to
        // blame, so this is not counted as a try, and it must be loud: every such mail is stuck.
        $this->given(
            TestData::queuedMail(10, 1, MailKind::ConfirmEmail, $this->cipher->seal(['confirmUrl' => 'https://app.example.test/verify-email?token=SECRET-TOKEN']), '2026-11-17 11:58:00', attempts: 2),
            TestData::queuedMail(11, 2, MailKind::ResetPassword, $this->sealed(), '2026-11-17 11:59:00'),
        );
        $this->templates->broken = ['confirm_email'];

        $report = $this->sender()->execute();

        self::assertEquals(new MailSendReport(1, 0, 0, 1), $report);
        self::assertSame(1, $report->notMade);
        // The other mail still went out, and no mail server was asked about the broken one.
        self::assertSame(['user2@example.org'], $this->mailer->attempts);
        // Kept as it was, and looked at again in 15 minutes: a repaired release sends it.
        self::assertEquals(
            [TestData::queuedMail(10, 1, MailKind::ConfirmEmail, $this->cipher->seal(['confirmUrl' => 'https://app.example.test/verify-email?token=SECRET-TOKEN']), '2026-11-17 11:58:00', '2026-11-17 12:15:00', 2)],
            $this->outbox->all(),
        );
        self::assertSame(
            [['level' => 'error', 'message' => 'A mail could not be made and was kept.', 'context' => ['template' => 'confirm_email', 'exception' => RuntimeException::class]]],
            $this->logger->records,
        );
        // The message of the failure names the address.
        self::assertStringNotContainsString('user1', $this->logger->text());
        self::assertStringNotContainsString('SECRET-TOKEN', $this->logger->text());

        // Repaired: the mail goes out with the tries it had, and the run is fine again.
        $this->templates->broken = [];
        $this->clock->advanceSeconds(900);
        self::assertEquals(new MailSendReport(1, 0, 0), $this->sender()->execute());
        self::assertSame([], $this->outbox->all());
    }

    public function test_a_mail_that_cannot_be_made_is_dropped_once_its_link_has_expired(): void
    {
        $this->given(TestData::queuedMail(10, 1, MailKind::ResetPassword, $this->sealed()));
        $this->templates->broken = ['reset_password'];

        // Looked at 12:00, 12:15, 12:30 and 12:45: kept each time. At 13:00 the link is dead.
        $runs = $this->runUntilTheOutboxIsEmpty();

        self::assertSame(5, $runs);
        self::assertSame([], $this->mailer->attempts);
        self::assertEquals(TestData::time('2026-11-17 13:00:00'), $this->clock->now());
        self::assertSame(
            ['level' => 'error', 'message' => 'A mail could not be sent and was deleted.', 'context' => ['template' => 'reset_password', 'exception' => null]],
            $this->logger->records[4] ?? null,
        );
        self::assertCount(5, $this->logger->records);
    }

    public function test_a_run_stops_taking_mails_after_40_seconds_and_puts_back_what_it_did_not_get_to(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->given(TestData::queuedMail(10 + $i, $i, sealedValues: $this->sealed(), createdAt: sprintf('2026-11-17 11:%02d:00', $i), attempts: $i === 6 ? 3 : 0));
        }
        // 0 s, 10 s, 20 s and 30 s into the run a mail is started. The fourth is done at 40 s.
        $this->mailer->secondsPerMail = 10;

        $report = $this->sender()->execute();

        self::assertEquals(new MailSendReport(4, 0, 0), $report);
        self::assertSame(['user1@example.org', 'user2@example.org', 'user3@example.org', 'user4@example.org'], $this->mailer->deliveredTo());
        // The two that were taken but not started are due at once again, as they were: being put
        // back is not a failed try.
        self::assertEquals(
            [
                TestData::queuedMail(15, 5, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:05:00'),
                TestData::queuedMail(16, 6, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:06:00', attempts: 3),
            ],
            $this->outbox->all(),
        );
        // And the run does not look for more.
        self::assertSame(1, $this->outbox->claims);

        // The next run, a minute later, sends them.
        $this->clock->advanceSeconds(20);
        self::assertEquals(new MailSendReport(2, 0, 0), $this->sender()->execute());
        self::assertSame([], $this->outbox->all());
    }

    public function test_a_run_whose_time_is_up_exactly_after_a_batch_does_not_take_another(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->given(TestData::queuedMail(10 + $i, $i, sealedValues: $this->sealed(), createdAt: sprintf('2026-11-17 11:%02d:00', $i)));
        }
        // Ten mails of four seconds each: the first batch is done 40 seconds into the run.
        $this->mailer->secondsPerMail = 4;

        $report = $this->sender()->execute();

        self::assertEquals(new MailSendReport(10, 0, 0), $report);
        self::assertSame(1, $this->outbox->claims);
        self::assertEquals([TestData::queuedMail(21, 11, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:11:00')], $this->outbox->all());
    }

    public function test_the_time_a_run_may_take_can_be_set(): void
    {
        $this->given(
            TestData::queuedMail(10, 1, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:01:00'),
            TestData::queuedMail(11, 2, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:02:00'),
            TestData::queuedMail(12, 3, sealedValues: $this->sealed(), createdAt: '2026-11-17 11:03:00'),
        );
        $this->mailer->secondsPerMail = 3;

        // Mails are started 0 s and 3 s into the run. At 6 s the five seconds are over.
        $report = $this->sender(timeBudgetSeconds: 5)->execute();

        self::assertEquals(new MailSendReport(2, 0, 0), $report);
        self::assertSame(40, SendQueuedMails::DEFAULT_TIME_BUDGET_SECONDS);
    }

    private function sender(?int $timeBudgetSeconds = null): SendQueuedMails
    {
        $templates = $this->templates;

        // Without a number: the default of the class, which is what the cron job runs with.
        return $timeBudgetSeconds === null
            ? new SendQueuedMails($this->transactions, $this->clock, $this->outbox, $this->cipher, $templates, $this->mailer, $this->logger)
            : new SendQueuedMails($this->transactions, $this->clock, $this->outbox, $this->cipher, $templates, $this->mailer, $this->logger, $timeBudgetSeconds);
    }

    private function given(QueuedMail ...$mails): void
    {
        $this->transactions->run(function () use ($mails): void {
            foreach ($mails as $mail) {
                $this->outbox->add($mail);
            }
        });
        // What the test arranged does not count as what the sender did.
        $this->transactions->commits = 0;
    }

    /**
     * Runs the sender each time the one waiting mail is due, until the outbox is empty.
     *
     * @return int How many runs it took.
     */
    private function runUntilTheOutboxIsEmpty(): int
    {
        $runs = 0;
        while ($this->outbox->all() !== []) {
            self::assertLessThan(500, $runs, 'The mail is tried for ever.');
            $due = $this->outbox->all()[0]->notBefore;
            $this->clock->advanceSeconds(max(0, $due->getTimestamp() - $this->clock->now()->getTimestamp()));
            $this->sender()->execute();
            $runs++;
        }

        return $runs;
    }

    /**
     * Values a mail can be made from, when the test does not care which.
     */
    private function sealed(): string
    {
        return $this->cipher->seal(['url' => 'https://app.example.test/somewhere']);
    }

    /**
     * @return list<QueuedMail> The mails a run has set aside: not due before a later moment.
     */
    private function leased(): array
    {
        return array_values(array_filter(
            $this->outbox->all(),
            fn (QueuedMail $mail): bool => $mail->notBefore > $this->clock->now(),
        ));
    }
}
