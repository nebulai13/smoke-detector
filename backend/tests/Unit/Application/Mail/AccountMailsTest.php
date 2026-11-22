<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Mail;

use LogicException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Mail\AccountMails;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Application\Mail\QueuedMail;
use SmokeDetector\Tests\Support\Fake\FakeMailValuesCipher;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Support\InMemory\InMemoryMailOutbox;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\SettingsFixture;
use SmokeDetector\Tests\Support\TestData;

/**
 * Which mail is queued for which occasion, for whom, and where its links point (HLD §4.5 "Mail").
 * Nothing is sent here: the mail goes into the outbox, inside the transaction of the use case.
 */
final class AccountMailsTest extends TestCase
{
    private InMemoryTransactionRunner $transactions;
    private InMemoryMailOutbox $outbox;
    private FakeMailValuesCipher $cipher;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->outbox = new InMemoryMailOutbox($this->transactions);
        $this->cipher = new FakeMailValuesCipher();
    }

    public function test_FR_ACC_01_the_confirmation_mail_is_queued_for_the_account_with_the_link_to_the_web_app(): void
    {
        $anna = TestData::user(7, 'anna@example.org', locale: Locale::De);
        $now = TestData::time('2026-11-17 12:00:00');

        $this->transactions->run(fn () => $this->mails()->confirmEmail($anna, 'AbC-_123', $now));

        self::assertEquals(
            [new QueuedMail(
                SequentialIdGenerator::id(1),
                $anna->id,
                MailKind::ConfirmEmail,
                Locale::De,
                $anna->email,
                $this->cipher->seal(['confirmUrl' => 'https://app.example.test/verify-email?token=AbC-_123']),
                // Due at once, and no try has failed yet.
                $now,
                $now,
                0,
            )],
            $this->outbox->all(),
        );
        self::assertSame('confirm_email', MailKind::ConfirmEmail->value);
    }

    public function test_FR_ACC_05_the_reset_mail_is_queued_for_the_account_with_the_link_to_the_web_app(): void
    {
        $anna = TestData::user(7, 'anna@example.org', locale: Locale::En);
        $now = TestData::time('2026-11-17 12:00:00');

        $this->transactions->run(fn () => $this->mails()->resetPassword($anna, 'AbC-_123', $now));

        self::assertEquals(
            [new QueuedMail(
                SequentialIdGenerator::id(1),
                $anna->id,
                MailKind::ResetPassword,
                Locale::En,
                $anna->email,
                $this->cipher->seal(['resetUrl' => 'https://app.example.test/reset-password?token=AbC-_123']),
                $now,
                $now,
                0,
            )],
            $this->outbox->all(),
        );
        self::assertSame('reset_password', MailKind::ResetPassword->value);
    }

    public function test_FR_ACC_01_the_already_registered_mail_points_to_login_and_password_reset(): void
    {
        $anna = TestData::user(7, 'anna@example.org', locale: Locale::En);
        $now = TestData::time('2026-11-17 12:00:00');

        $this->transactions->run(fn () => $this->mails()->alreadyRegistered($anna, $now));

        self::assertEquals(
            [new QueuedMail(
                SequentialIdGenerator::id(1),
                $anna->id,
                MailKind::AlreadyRegistered,
                Locale::En,
                $anna->email,
                $this->cipher->seal(['loginUrl' => 'https://app.example.test/login', 'resetUrl' => 'https://app.example.test/reset-password']),
                $now,
                $now,
                0,
            )],
            $this->outbox->all(),
        );
        self::assertSame('already_registered', MailKind::AlreadyRegistered->value);
    }

    public function test_NFR_05_the_link_with_its_token_is_stored_encrypted_only(): void
    {
        // one_time_tokens keeps only the hash of a token. The outbox must not undo that.
        $anna = TestData::user(7, 'anna@example.org');
        $now = TestData::time('2026-11-17 12:00:00');

        $mails = $this->mails();
        $this->transactions->run(function () use ($mails, $anna, $now): void {
            $mails->confirmEmail($anna, 'SECRET-TOKEN', $now);
            $mails->resetPassword($anna, 'SECRET-TOKEN', $now);
        });

        self::assertCount(2, $this->outbox->all());
        foreach ($this->outbox->all() as $queued) {
            self::assertStringNotContainsString('SECRET-TOKEN', serialize($queued));
            self::assertStringNotContainsString('https://', serialize($queued));
            // And what is stored is what the cipher made: the sender can open it.
            self::assertStringContainsString('token=SECRET-TOKEN', implode(' ', $this->cipher->open($queued->sealedValues)));
        }
    }

    public function test_a_token_is_encoded_for_the_url(): void
    {
        // Real tokens are base64url and need no encoding. Whatever comes must not break the link.
        $anna = TestData::user(7);
        $now = TestData::time('2026-11-17 12:00:00');

        $mails = $this->mails();
        $this->transactions->run(function () use ($mails, $anna, $now): void {
            $mails->confirmEmail($anna, 'a+b/c=d&e f#g', $now);
            $mails->resetPassword($anna, 'a+b/c=d&e f#g', $now);
        });

        self::assertSame(
            [
                ['confirmUrl' => 'https://app.example.test/verify-email?token=a%2Bb%2Fc%3Dd%26e%20f%23g'],
                ['resetUrl' => 'https://app.example.test/reset-password?token=a%2Bb%2Fc%3Dd%26e%20f%23g'],
            ],
            array_map(fn (QueuedMail $mail): array => $this->cipher->open($mail->sealedValues), $this->outbox->all()),
        );
    }

    public function test_links_start_with_the_app_url_of_the_settings(): void
    {
        $mails = new AccountMails($this->outbox, $this->cipher, new SequentialIdGenerator(), 'https://smoke.example.org/app');

        $this->transactions->run(fn () => $mails->confirmEmail(TestData::user(7), 't', TestData::time('2026-11-17 12:00:00')));

        self::assertSame(
            ['confirmUrl' => 'https://smoke.example.org/app/verify-email?token=t'],
            $this->cipher->open($this->outbox->all()[0]->sealedValues),
        );
    }

    public function test_every_mail_gets_an_id_of_its_own(): void
    {
        $mails = $this->mails();
        $anna = TestData::user(7);
        $now = TestData::time('2026-11-17 12:00:00');

        $this->transactions->run(function () use ($mails, $anna, $now): void {
            $mails->confirmEmail($anna, 'a', $now);
            $mails->alreadyRegistered($anna, $now);
            $mails->resetPassword($anna, 'b', $now);
        });

        self::assertSame(
            [SequentialIdGenerator::id(1), SequentialIdGenerator::id(2), SequentialIdGenerator::id(3)],
            array_map(static fn (QueuedMail $mail): string => $mail->id, $this->outbox->all()),
        );
    }

    public function test_a_mail_can_only_be_queued_inside_the_transaction_that_stores_its_token(): void
    {
        // Otherwise "token stored" and "mail queued" could come apart: a link nobody is mailed,
        // or a mail whose link was rolled back.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MailOutbox::add() must be called inside a transaction.');

        $this->mails()->confirmEmail(TestData::user(7), 't', TestData::time('2026-11-17 12:00:00'));
    }

    private function mails(): AccountMails
    {
        return new AccountMails($this->outbox, $this->cipher, new SequentialIdGenerator(), SettingsFixture::settings()->appUrl);
    }
}
