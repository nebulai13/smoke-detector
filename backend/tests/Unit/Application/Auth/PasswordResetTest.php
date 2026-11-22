<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\AuthLimits;
use SmokeDetector\Application\Auth\InvalidAccessToken;
use SmokeDetector\Application\Auth\InvalidCredentials;
use SmokeDetector\Application\Auth\InvalidRefreshToken;
use SmokeDetector\Application\Auth\InvalidToken;
use SmokeDetector\Application\Auth\OneTimeToken;
use SmokeDetector\Application\Auth\Session;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\ValidationFailed;

/**
 * POST /auth/password-reset/request and /confirm (HLD §4.5 "Password reset", FR-ACC-05).
 */
final class PasswordResetTest extends AuthUseCaseTestCase
{
    private const string NEW_PASSWORD = 'a brand new password';

    public function test_FR_ACC_05_a_confirmed_account_is_mailed_a_reset_link_in_its_language(): void
    {
        $user = $this->givenUser(locale: Locale::En);

        $this->requestPasswordReset()->execute(self::email('Anna@Example.ORG'), self::CLIENT);

        $mail = $this->theMail();
        self::assertSame('anna@example.org', $mail->to->value);
        self::assertSame('reset_password', $mail->template);
        self::assertSame('reset_password [en]', $mail->subject);
        self::assertSame('resetUrl=https://app.example.test/reset-password?token=token-1', $mail->text);
        // Asking for a reset changes nothing about the account: the old password still works.
        self::assertEquals([$user], $this->users->all());
    }

    public function test_FR_ACC_05_the_reset_mail_is_queued_for_the_account_and_not_sent_by_the_request(): void
    {
        $user = $this->givenUser();
        $this->clock->advanceSeconds(90);

        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);

        $queued = $this->outbox->all();
        self::assertCount(1, $queued);
        self::assertSame('reset_password', $queued[0]->kind->value);
        self::assertTrue($queued[0]->userId->equals($user->id));
        self::assertSame('anna@example.org', $queued[0]->to->value);
        // Queued when the token was made: the mail is dropped when the token has expired.
        self::assertEquals($this->now(), $queued[0]->createdAt);
        self::assertEquals($this->now(), $queued[0]->notBefore);
        self::assertEquals($this->oneTimeTokens->all()[0]->expiresAt, $queued[0]->expiresAt());
        self::assertStringNotContainsString('token-1', serialize($queued[0]));
    }

    public function test_FR_ACC_05_a_reset_link_whose_mail_cannot_be_queued_is_not_stored_either(): void
    {
        // The mail is queued in the transaction that stores the link. Otherwise an earlier link,
        // one that was mailed, could stop working for a link nobody is ever mailed.
        $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $before = [$this->oneTimeTokens->all(), $this->outbox->all()];
        $this->outbox->failNextAdds = 1;

        try {
            $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
            self::fail('Expected the failure to be passed on.');
        } catch (RuntimeException $failure) {
            self::assertSame('The mail could not be queued.', $failure->getMessage());
        }

        self::assertEquals($before, [$this->oneTimeTokens->all(), $this->outbox->all()]);
        self::assertTrue($this->hasOneTimeToken('token-1', TokenPurpose::ResetPassword));
        self::assertSame(1, $this->transactions->rollbacks);
    }

    public function test_FR_ACC_05_the_reset_link_is_valid_1_hour_and_stored_as_a_hash_only(): void
    {
        $user = $this->givenUser();

        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);

        $stored = $this->oneTimeTokens->all();
        self::assertCount(1, $stored);
        self::assertSame(TokenPurpose::ResetPassword, $stored[0]->purpose);
        self::assertSame(hash('sha256', 'token-1'), $stored[0]->tokenHash);
        self::assertTrue($stored[0]->userId->equals($user->id));
        self::assertEquals(new DateTimeImmutable('2026-11-17 13:00:00 UTC'), $stored[0]->expiresAt);
        self::assertNull($stored[0]->usedAt);
    }

    public function test_FR_ACC_05_an_unknown_email_gets_no_mail_and_no_error(): void
    {
        $this->requestPasswordReset()->execute(self::email('nobody@example.org'), self::CLIENT);

        self::assertSame([], $this->queuedMails());
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertSame(1, $this->transactions->commits);
    }

    public function test_FR_ACC_05_an_unconfirmed_registration_gets_no_reset_link(): void
    {
        // Whoever registered an address they do not own must not get a second way to activate it,
        // and the mailbox owner did not give the consents that are stored with it.
        $user = $this->givenUser(verified: false);

        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);

        self::assertSame([], $this->queuedMails());
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertEquals([$user], $this->users->all());
    }

    public function test_FR_ACC_05_only_the_latest_reset_link_works(): void
    {
        $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);

        self::assertFalse($this->hasOneTimeToken('token-1', TokenPurpose::ResetPassword));
        self::assertCount(1, $this->oneTimeTokens->all());
        $this->expectInvalidToken('token-1');
        $this->resetPassword()->execute('token-2', self::NEW_PASSWORD);
        self::assertSame('fake-v1:' . self::NEW_PASSWORD, $this->users->all()[0]->passwordHash);
    }

    public function test_FR_ACC_05_asking_for_a_reset_leaves_a_pending_confirmation_link_alone(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->confirm('token-1');
        $this->oneTimeTokens->put(new OneTimeToken(
            $this->ids->next(),
            $this->users->all()[0]->id,
            TokenPurpose::VerifyEmail,
            hash('sha256', 'another-verify-token'),
            $this->now()->modify('+1 day'),
            null,
        ));

        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);

        self::assertTrue($this->hasOneTimeToken('another-verify-token', TokenPurpose::VerifyEmail));
        self::assertTrue($this->hasOneTimeToken($this->tokens->last(), TokenPurpose::ResetPassword));
    }

    public function test_NFR_05_one_client_can_ask_for_3_resets_of_an_address_per_hour_and_does_not_lock_it_for_the_owner(): void
    {
        $stranger = '198.51.100.66';
        $this->givenUser();
        for ($i = 1; $i <= 3; $i++) {
            $this->requestPasswordReset()->execute(self::email(), $stranger);
        }
        $this->outbox->clear();
        $this->clock->advanceSeconds(1800);

        try {
            $this->requestPasswordReset()->execute(self::email(), $stranger);
            self::fail('Expected the request to be refused.');
        } catch (RateLimitExceeded $exceeded) {
            self::assertSame(1800, $exceeded->retryAfterSeconds);
        }

        self::assertSame([], $this->queuedMails());
        self::assertTrue($this->hasOneTimeToken('token-3', TokenPurpose::ResetPassword));
        self::assertSame(3, $this->transactions->commits);
        // The owner, somewhere else, still gets a link (SRS OQ-11).
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        self::assertSame('reset_password', $this->theMail()->template);
        self::assertTrue($this->hasOneTimeToken('token-4', TokenPurpose::ResetPassword));
    }

    public function test_NFR_05_the_eleventh_reset_mail_to_an_address_within_an_hour_is_left_out_and_the_last_link_keeps_working(): void
    {
        $this->givenUser();
        for ($i = 1; $i <= 10; $i++) {
            $this->requestPasswordReset()->execute(self::email(), "198.51.100.{$i}");
        }
        self::assertCount(10, $this->queuedMails());
        $this->outbox->clear();
        $before = $this->oneTimeTokens->all();
        $commits = $this->transactions->commits;

        // No exception: answered like every other request, also for an address nobody has.
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        for ($i = 1; $i <= 11; $i++) {
            $this->requestPasswordReset()->execute(self::email('nobody@example.org'), "198.51.100.{$i}");
        }

        self::assertSame([], $this->queuedMails());
        self::assertEquals($before, $this->oneTimeTokens->all());
        self::assertSame($commits + 10, $this->transactions->commits);
        // The link of the tenth mail is still the one that works.
        $this->resetPassword()->execute('token-10', self::NEW_PASSWORD);
        self::assertSame('fake-v1:' . self::NEW_PASSWORD, $this->users->all()[0]->passwordHash);
    }

    public function test_NFR_05_one_client_address_can_ask_for_5_resets_per_hour_whatever_the_email(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->requestPasswordReset()->execute(self::email("nobody{$i}@example.org"), self::CLIENT);
        }

        try {
            $this->requestPasswordReset()->execute(self::email('nobody6@example.org'), self::CLIENT);
            self::fail('Expected the request to be refused.');
        } catch (RateLimitExceeded $exceeded) {
            self::assertSame(3600, $exceeded->retryAfterSeconds);
        }
        self::assertSame(0, $this->hits(AuthLimits::mailsPerAddress(), 'nobody6@example.org'));
        self::assertSame(0, $this->hits(AuthLimits::mailsPerPair(), 'nobody6@example.org', self::CLIENT));
    }

    public function test_FR_ACC_05_the_link_sets_the_new_password(): void
    {
        $user = $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $this->clock->advanceSeconds(600);

        $this->resetPassword()->execute('token-1', self::NEW_PASSWORD);

        // Only the password changed, and the token version that ends the access tokens from before.
        self::assertEquals([$user->withPasswordHash('fake-v1:' . self::NEW_PASSWORD)->withNextTokenVersion()], $this->users->all());
        self::assertEquals($this->now(), $this->oneTimeTokens->all()[0]->usedAt);
        // The new password works, the old one does not.
        self::assertInstanceOf(Session::class, $this->logIn()->execute(self::email(), self::NEW_PASSWORD, null, self::CLIENT));
        try {
            $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);
            self::fail('Expected the old password to be refused.');
        } catch (InvalidCredentials) {
        }
    }

    public function test_FR_ACC_05_a_reset_does_not_log_in(): void
    {
        $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);

        $this->resetPassword()->execute('token-1', self::NEW_PASSWORD);

        self::assertSame([], $this->refreshTokens->all());
    }

    public function test_FR_ACC_05_a_successful_reset_signs_the_user_out_on_all_devices(): void
    {
        $anna = $this->givenUser();
        $this->givenUser('bob@example.org');
        $authenticate = $this->authenticate();
        $phone = $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);                    // session key token-1
        $laptop = $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);                   // session key token-2
        $bob = $this->logIn()->execute(self::email('bob@example.org'), self::PASSWORD, null, self::CLIENT);     // session key token-3
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);                            // token-4
        $this->clock->advanceSeconds(60);

        $this->resetPassword()->execute('token-4', self::NEW_PASSWORD);

        // The refresh tokens of every device of the user are dead …
        foreach ([$phone, $laptop] as $session) {
            try {
                $this->refreshSession()->execute($session->refreshToken);
                self::fail('Expected every session of the user to be over.');
            } catch (InvalidRefreshToken) {
            }
        }
        [$phoneRow, $laptopRow, $bobRow] = $this->refreshTokens->all();
        self::assertEquals($this->now(), $phoneRow->revokedAt);
        self::assertEquals($this->now(), $laptopRow->revokedAt);
        // … and so are the access tokens that were handed out before, at once and not when they expire.
        foreach ([$phone, $laptop] as $session) {
            try {
                $authenticate->execute($session->accessToken->token);
                self::fail('Expected the access token from before the reset to be refused.');
            } catch (InvalidAccessToken) {
            }
        }
        self::assertSame(1, $this->users->find($anna->id)?->tokenVersion);

        // Other users stay signed in.
        self::assertNull($bobRow->revokedAt);
        self::assertInstanceOf(Session::class, $this->refreshSession()->execute($bob->refreshToken));
        self::assertInstanceOf(UserId::class, $authenticate->execute($bob->accessToken->token));
        // And the user logs in again with the new password.
        $again = $this->logIn()->execute(self::email(), self::NEW_PASSWORD, null, self::CLIENT);
        self::assertTrue($anna->id->equals($authenticate->execute($again->accessToken->token)));
    }

    public function test_FR_ACC_05_a_second_reset_ends_the_sessions_since_the_first(): void
    {
        $anna = $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $this->resetPassword()->execute('token-1', self::NEW_PASSWORD);
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);

        $this->resetPassword()->execute('token-2', 'yet another new password');

        self::assertSame(2, $this->users->find($anna->id)?->tokenVersion);
    }

    public function test_NFR_05_a_successful_reset_clears_the_failed_logins_counted_for_the_address_over_all_clients(): void
    {
        // Wrong guesses from many clients can lock an address for everybody (50 an hour). The
        // owner has just proven to hold the mailbox and set a password nobody has guessed at: that
        // lock has no purpose left.
        $this->givenUser();
        $this->givenUser('bob@example.org');
        for ($i = 1; $i <= 5; $i++) {
            foreach (['anna@example.org', 'bob@example.org'] as $address) {
                try {
                    $this->logIn()->execute(self::email($address), "guess {$i}", null, "198.51.100.{$i}");
                } catch (InvalidCredentials) {
                }
            }
        }
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);

        $this->resetPassword()->execute('token-1', self::NEW_PASSWORD);

        self::assertSame(0, $this->hits(AuthLimits::loginFailuresPerAddress(), 'anna@example.org'));
        self::assertInstanceOf(Session::class, $this->logIn()->execute(self::email(), self::NEW_PASSWORD, null, self::CLIENT));
        // Only the overall counter of that address: not another address's, not the clients', not
        // what one client guessed at this address, not the mails'.
        self::assertSame(5, $this->hits(AuthLimits::loginFailuresPerAddress(), 'bob@example.org'));
        self::assertSame(2, $this->hits(AuthLimits::loginFailuresPerClient(), '198.51.100.1'));
        self::assertSame(1, $this->hits(AuthLimits::loginFailuresPerPair(), 'anna@example.org', '198.51.100.1'));
        self::assertSame(1, $this->hits(AuthLimits::mailsPerAddress(), 'anna@example.org'));
    }

    public function test_NFR_05_a_refused_reset_clears_no_counter(): void
    {
        $this->givenUser();
        try {
            $this->logIn()->execute(self::email(), 'a wrong guess', null, self::CLIENT);
        } catch (InvalidCredentials) {
        }

        $this->expectInvalidToken('no such token', self::NEW_PASSWORD);

        self::assertSame(1, $this->hits(AuthLimits::loginFailuresPerAddress(), 'anna@example.org'));
    }

    public function test_the_account_is_locked_before_the_token_is(): void
    {
        // The one order every use case takes its locks in: a new reset request holds the account
        // and then replaces the token.
        $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $this->transactions->locks = [];

        $this->resetPassword()->execute('token-1', self::NEW_PASSWORD);

        self::assertSame(['users', 'one_time_tokens'], $this->transactions->locks);
    }

    public function test_FR_ACC_05_a_reset_link_works_only_once(): void
    {
        $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $this->resetPassword()->execute('token-1', self::NEW_PASSWORD);

        $this->expectInvalidToken('token-1', 'yet another password');

        self::assertSame('fake-v1:' . self::NEW_PASSWORD, $this->users->all()[0]->passwordHash);
    }

    public function test_FR_ACC_05_a_reset_link_works_until_just_before_the_hour_is_over(): void
    {
        $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $this->clock->advanceSeconds(3599);

        $this->resetPassword()->execute('token-1', self::NEW_PASSWORD);

        self::assertSame('fake-v1:' . self::NEW_PASSWORD, $this->users->all()[0]->passwordHash);
    }

    public function test_FR_ACC_05_a_reset_link_expires_after_1_hour(): void
    {
        $user = $this->givenUser();
        $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $this->clock->advanceSeconds(3600);

        // (The login made token-1: the key of its session.)
        self::assertTrue($this->hasOneTimeToken('token-2', TokenPurpose::ResetPassword));
        $this->expectInvalidToken('token-2');

        self::assertEquals([$user], $this->users->all());
        self::assertNull($this->refreshTokens->all()[0]->revokedAt);
        self::assertNull($this->oneTimeTokens->all()[0]->usedAt);
    }

    public function test_FR_ACC_05_an_unknown_token_is_refused_before_anything_is_hashed(): void
    {
        $user = $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);

        $this->expectInvalidToken('token-2');
        $this->expectInvalidToken('');
        $this->expectInvalidToken(hash('sha256', 'token-1'));

        self::assertEquals([$user], $this->users->all());
        // Hashing is expensive. A stranger without a token must not be able to make us do it.
        self::assertSame([], $this->hasher->hashed);
    }

    public function test_NFR_05_a_confirmation_token_does_not_reset_a_password(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);

        $this->expectInvalidToken('token-1');

        self::assertSame('fake-v1:' . self::PASSWORD, $this->users->all()[0]->passwordHash);
        self::assertNull($this->oneTimeTokens->all()[0]->usedAt);
    }

    public function test_FR_ACC_03_an_unconfirmed_account_cannot_be_activated_or_changed_through_a_reset(): void
    {
        // Cannot come about through the request. If such a token ever exists, it must not work.
        $user = $this->givenUser(verified: false);
        $this->oneTimeTokens->put(new OneTimeToken(
            $this->ids->next(),
            $user->id,
            TokenPurpose::ResetPassword,
            hash('sha256', 'planted'),
            $this->now()->modify('+1 hour'),
            null,
        ));

        $this->expectInvalidToken('planted');

        self::assertEquals([$user], $this->users->all());
        self::assertFalse($this->users->all()[0]->isVerified());
        // Rolled back as a whole: the token was not used up either.
        self::assertNull($this->oneTimeTokens->all()[0]->usedAt);
        self::assertSame([], $this->hasher->hashed);
    }

    public function test_a_reset_token_whose_account_is_gone_is_refused(): void
    {
        $user = $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $this->users->delete($user->id);

        $this->expectInvalidToken('token-1');

        self::assertSame([], $this->hasher->hashed);
    }

    /**
     * @return iterable<string, array{string, string|null}> The password, and the code it is rejected with (null when it is accepted).
     */
    public static function passwords(): iterable
    {
        yield '9 characters' => [str_repeat('a', 9), 'too_short'];
        yield '10 characters' => [str_repeat('a', 10), null];
        yield '200 characters' => [str_repeat('ä', 200), null];
        yield '201 characters' => [str_repeat('a', 201), 'too_long'];
    }

    #[DataProvider('passwords')]
    public function test_FR_ACC_05_the_new_password_has_10_to_200_characters(string $password, ?string $rejectedAs): void
    {
        $accepted = $rejectedAs === null;
        $user = $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);

        try {
            $this->resetPassword()->execute('token-1', $password);
            self::assertTrue($accepted, 'Expected the password to be rejected.');
            self::assertSame("fake-v1:{$password}", $this->users->all()[0]->passwordHash);
        } catch (ValidationFailed $failure) {
            self::assertFalse($accepted, 'Expected the password to be accepted.');
            self::assertSame([['password', $rejectedAs]], array_map(static fn (FieldError $error): array => [$error->field, $error->code->value], $failure->errors));
            // The link is still good for a second try with a better password.
            self::assertEquals([$user], $this->users->all());
            self::assertNull($this->oneTimeTokens->all()[0]->usedAt);
            self::assertSame(1, $this->transactions->commits);
            self::assertSame(0, $this->transactions->rollbacks);
        }
    }

    public function test_NFR_05_the_new_password_is_hashed_exactly_as_typed(): void
    {
        $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $password = "  new pass\u{00A0}wörd \t ";

        $this->resetPassword()->execute('token-1', $password);

        self::assertSame([$password], $this->hasher->hashed);
    }

    private function expectInvalidToken(string $token, string $password = self::NEW_PASSWORD): void
    {
        try {
            $this->resetPassword()->execute($token, $password);
            self::fail('Expected the token to be refused.');
        } catch (InvalidToken $refused) {
            // The same for every reason.
            self::assertSame('The token is invalid, expired or already used.', $refused->getMessage());
        }
    }
}
