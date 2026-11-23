<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use DateTimeImmutable;
use RuntimeException;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Auth\AuthLimits;
use SmokeDetector\Application\Auth\InvalidToken;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;

/**
 * POST /auth/resend-verification (HLD §4.5 "Registration" step 6, FR-ACC-03).
 */
final class ResendVerificationTest extends AuthUseCaseTestCase
{
    public function test_FR_ACC_03_an_unconfirmed_registration_gets_a_new_link_in_its_language(): void
    {
        $this->registerUser()->execute(self::registration(locale: Locale::En), self::CLIENT);
        $before = $this->users->all();
        $this->outbox->clear();
        $this->clock->advanceSeconds(1800);

        $this->resendVerification()->execute(self::email('ANNA@example.org'), self::CLIENT);

        $mail = $this->theMail();
        self::assertSame('anna@example.org', $mail->to->value);
        self::assertSame('confirm_email [en]', $mail->subject);
        self::assertSame('confirmUrl=https://app.example.test/verify-email?token=token-2', $mail->text);
        // The link is good for 24 hours, as the mail says, so the registration stays as long: its
        // 24 hours start again. Nothing else about it changes.
        self::assertEquals(new DateTimeImmutable('2026-11-18 12:30:00 UTC'), $this->oneTimeTokens->all()[0]->expiresAt);
        self::assertEquals([$before[0]->registeredAt(new DateTimeImmutable('2026-11-17 12:30:00 UTC'))], $this->users->all());
        self::assertNotEquals($before, $this->users->all());
        self::assertSame([self::PASSWORD], $this->hasher->hashed);
    }

    public function test_FR_ACC_03_a_new_link_whose_mail_cannot_be_queued_does_not_replace_the_one_that_was_mailed(): void
    {
        // The mail is queued in the transaction that stores the new link. Otherwise the link of
        // the last mail could stop working for a link nobody is ever mailed.
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $before = [$this->users->all(), $this->oneTimeTokens->all(), $this->outbox->all()];
        $this->clock->advanceSeconds(1800);
        $this->outbox->failNextAdds = 1;

        try {
            $this->resendVerification()->execute(self::email(), self::CLIENT);
            self::fail('Expected the failure to be passed on.');
        } catch (RuntimeException $failure) {
            self::assertSame('The mail could not be queued.', $failure->getMessage());
        }

        self::assertEquals($before, [$this->users->all(), $this->oneTimeTokens->all(), $this->outbox->all()]);
        self::assertTrue($this->hasOneTimeToken('token-1', TokenPurpose::VerifyEmail));
        self::assertSame(1, $this->transactions->rollbacks);
    }

    public function test_FR_ACC_01_the_note_for_a_confirmed_account_is_queued_for_that_account(): void
    {
        $anna = $this->givenUser(locale: Locale::En);

        $this->resendVerification()->execute(self::email(), self::CLIENT);

        $queued = $this->outbox->all();
        self::assertCount(1, $queued);
        self::assertSame('already_registered', $queued[0]->kind->value);
        self::assertTrue($queued[0]->userId->equals($anna->id));
        self::assertEquals($this->now(), $queued[0]->createdAt);
        self::assertSame('already_registered [en]', $this->theMail()->subject);
    }

    public function test_FR_ACC_03_only_the_latest_link_works(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);

        $this->resendVerification()->execute(self::email(), self::CLIENT);

        self::assertFalse($this->hasOneTimeToken('token-1', TokenPurpose::VerifyEmail));
        self::assertCount(1, $this->oneTimeTokens->all());
        try {
            $this->confirm('token-1');
            self::fail('Expected the older link to be refused.');
        } catch (InvalidToken) {
        }
        $this->confirm('token-2');
        self::assertTrue($this->users->all()[0]->isVerified());
    }

    public function test_FR_ACC_03_a_link_that_was_sent_again_late_still_works_for_its_24_hours(): void
    {
        // Registered at 12:00, asked again at 11:00 the next day, clicked at 12:30: the mail of
        // 11:00 promised 24 hours, and the cleanup in between must not take the registration away.
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->clock->advanceSeconds(23 * 3600);
        $this->resendVerification()->execute(self::email(), self::CLIENT);
        $this->clock->advanceSeconds(5400);

        self::assertSame(0, $this->purgeExpiredData()->execute()->unconfirmedRegistrations);
        $this->confirm('token-2');

        self::assertTrue($this->users->all()[0]->isVerified());
    }

    public function test_FR_ACC_03_a_registration_older_than_24_hours_gets_no_new_link(): void
    {
        // It is to be deleted. Until the cleanup has done that, it must not come back to life.
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->outbox->clear();
        $this->clock->advanceSeconds(86_400);
        $before = [$this->users->all(), $this->oneTimeTokens->all()];

        $this->resendVerification()->execute(self::email(), self::CLIENT);

        self::assertSame([], $this->queuedMails());
        self::assertEquals($before, [$this->users->all(), $this->oneTimeTokens->all()]);
    }

    public function test_FR_ACC_03_a_registration_gets_a_new_link_until_the_last_second_of_its_24_hours(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->outbox->clear();
        $this->clock->advanceSeconds(86_399);

        $this->resendVerification()->execute(self::email(), self::CLIENT);

        self::assertSame('confirm_email [de]', $this->theMail()->subject);
    }

    public function test_FR_ACC_03_an_unknown_email_gets_no_mail_and_no_error(): void
    {
        $this->resendVerification()->execute(self::email('nobody@example.org'), self::CLIENT);

        self::assertSame([], $this->queuedMails());
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertSame(1, $this->transactions->commits);
    }

    public function test_FR_ACC_03_a_confirmed_account_is_told_that_it_exists_and_gets_no_link(): void
    {
        $user = $this->givenUser(locale: Locale::En);

        $this->resendVerification()->execute(self::email(), self::CLIENT);

        $mail = $this->theMail();
        self::assertSame('anna@example.org', $mail->to->value);
        self::assertSame('already_registered [en]', $mail->subject);
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertEquals([$user], $this->users->all());
    }

    public function test_NFR_05_resending_counts_towards_the_same_limits_as_registering(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->resendVerification()->execute(self::email(), self::CLIENT);
        $this->resendVerification()->execute(self::email(), self::CLIENT);
        self::assertSame(3, $this->hits(AuthLimits::mailsPerClient(), self::CLIENT));
        self::assertSame(3, $this->hits(AuthLimits::mailsPerPair(), 'anna@example.org', self::CLIENT));
        self::assertSame(3, $this->hits(AuthLimits::mailsPerAddress(), 'anna@example.org'));
        $this->outbox->clear();

        // The fourth mail this client asks for to this address within the hour is refused.
        try {
            $this->resendVerification()->execute(self::email(), self::CLIENT);
            self::fail('Expected the request to be refused.');
        } catch (RateLimitExceeded $exceeded) {
            self::assertSame(3600, $exceeded->retryAfterSeconds);
        }

        self::assertSame([], $this->queuedMails());
        self::assertTrue($this->hasOneTimeToken('token-3', TokenPurpose::VerifyEmail));
        // Not held against the address, and not a lock on it: from elsewhere the link is sent again.
        self::assertSame(3, $this->hits(AuthLimits::mailsPerAddress(), 'anna@example.org'));
        $this->resendVerification()->execute(self::email(), '198.51.100.2');
        self::assertCount(1, $this->queuedMails());
        self::assertTrue($this->hasOneTimeToken('token-4', TokenPurpose::VerifyEmail));
    }

    public function test_NFR_05_the_eleventh_mail_to_an_address_within_an_hour_is_left_out_and_the_last_link_keeps_working(): void
    {
        $this->registerUser()->execute(self::registration(), '198.51.100.1');
        for ($i = 2; $i <= 10; $i++) {
            $this->resendVerification()->execute(self::email(), "198.51.100.{$i}");
        }
        self::assertCount(10, $this->queuedMails());
        $this->outbox->clear();
        $this->clock->advanceSeconds(600);
        $before = [$this->users->all(), $this->oneTimeTokens->all()];
        $commits = $this->transactions->commits;

        // No exception: the request is answered like every other.
        $this->resendVerification()->execute(self::email(), self::CLIENT);

        // No mail, and so no new link either: the link of the tenth mail must not die for a mail
        // that never arrives, and the 24 hours of the registration do not start again.
        self::assertSame([], $this->queuedMails());
        self::assertEquals($before, [$this->users->all(), $this->oneTimeTokens->all()]);
        self::assertSame($commits, $this->transactions->commits);
        self::assertTrue($this->hasOneTimeToken('token-10', TokenPurpose::VerifyEmail));
        $this->confirm('token-10');
        self::assertTrue($this->users->all()[0]->isVerified());
    }

    public function test_NFR_05_one_client_address_can_ask_5_times_per_hour_whatever_the_email(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->resendVerification()->execute(self::email("nobody{$i}@example.org"), self::CLIENT);
        }

        try {
            $this->resendVerification()->execute(self::email('nobody6@example.org'), self::CLIENT);
            self::fail('Expected the request to be refused.');
        } catch (RateLimitExceeded $exceeded) {
            self::assertSame(3600, $exceeded->retryAfterSeconds);
        }
        self::assertSame(0, $this->hits(AuthLimits::mailsPerAddress(), 'nobody6@example.org'));
        self::assertSame(0, $this->hits(AuthLimits::mailsPerPair(), 'nobody6@example.org', self::CLIENT));
        self::assertSame(5, $this->transactions->commits);
    }
}
