<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Auth\AuthLimits;
use SmokeDetector\Application\Auth\DeviceLabel;
use SmokeDetector\Application\Auth\InvalidCredentials;
use SmokeDetector\Application\Auth\InvalidRefreshToken;
use SmokeDetector\Application\Auth\RefreshToken;
use SmokeDetector\Application\Auth\Session;
use SmokeDetector\Application\Auth\Sessions;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Tests\Support\Fake\FakePasswordHasher;
use SmokeDetector\Tests\Support\InMemory\InMemoryUserRepository;
use Throwable;

/**
 * POST /auth/login (HLD §4.5 "Login and tokens", FR-ACC-04, NFR-05).
 */
final class LogInTest extends AuthUseCaseTestCase
{
    public function test_FR_ACC_04_email_and_password_give_a_session(): void
    {
        $user = $this->givenUser();

        $session = $this->logIn()->execute(self::email(), self::PASSWORD, DeviceLabel::fromInput('Annas iPhone'), self::CLIENT);

        self::assertSame('access-1-v0-for-' . $user->id->value, $session->accessToken->token);
        self::assertSame(900, $session->accessToken->expiresInSeconds);
        // The key of the session, a dot, and the secret of its first token (generation 0), which
        // every refresh replaces by the next one.
        self::assertSame('token-1.secret-0-of-token-1', $session->refreshToken);
        self::assertEquals(new DateTimeImmutable('2027-01-16 12:00:00 UTC'), $session->refreshTokenExpiresAt);
    }

    public function test_NFR_05_the_refresh_token_is_stored_as_a_hash_and_valid_60_days(): void
    {
        $user = $this->givenUser();

        $this->logIn()->execute(self::email(), self::PASSWORD, DeviceLabel::fromInput('Annas iPhone'), self::CLIENT);

        $stored = $this->refreshTokens->all();
        self::assertCount(1, $stored);
        self::assertSame(hash('sha256', 'token-1'), $stored[0]->keyHash);
        self::assertSame(hash('sha256', 'token-1.secret-0-of-token-1'), $stored[0]->tokenHash);
        self::assertSame(0, $stored[0]->generation);
        self::assertNull($stored[0]->rotatedAt);
        self::assertTrue($stored[0]->userId->equals($user->id));
        self::assertSame('Annas iPhone', $stored[0]->deviceLabel?->value);
        self::assertEquals($this->now(), $stored[0]->createdAt);
        self::assertEquals($this->now()->modify('+60 days'), $stored[0]->expiresAt);
        self::assertNull($stored[0]->rotatedAt);
        self::assertNull($stored[0]->revokedAt);
    }

    public function test_FR_ACC_04_every_login_starts_its_own_session(): void
    {
        $this->givenUser();

        $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);
        $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);

        $stored = $this->refreshTokens->all();
        self::assertCount(2, $stored);
        self::assertNotSame($stored[0]->id, $stored[1]->id);
        self::assertNotSame($stored[0]->keyHash, $stored[1]->keyHash);
        self::assertNull($stored[0]->deviceLabel);
    }

    public function test_NFR_05_a_user_has_at_most_20_sessions_and_the_one_unused_longest_goes_first(): void
    {
        // Otherwise logging in again and again would fill the table: a login costs no failed attempt.
        $this->givenUser();
        $this->givenUser('bob@example.org');
        $bob = $this->logIn()->execute(self::email('bob@example.org'), self::PASSWORD, null, self::CLIENT);
        $sessions = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->clock->advanceSeconds(60);
            $sessions[$i] = $this->logIn()->execute(self::email(), self::PASSWORD, DeviceLabel::fromInput("Device {$i}"), self::CLIENT);
        }
        // The oldest login is the one used last, so it is not the one to go.
        $this->clock->advanceSeconds(60);
        $kept = $this->refreshSession()->execute($sessions[1]->refreshToken);
        self::assertSame(Sessions::MAX_PER_USER, 20);
        self::assertCount(21, $this->refreshTokens->all());

        $this->clock->advanceSeconds(60);
        $this->logIn()->execute(self::email(), self::PASSWORD, DeviceLabel::fromInput('Device 21'), self::CLIENT);

        $labels = array_map(static fn (RefreshToken $session): ?string => $session->deviceLabel?->value, $this->refreshTokens->all());
        self::assertSame([null, 'Device 1', ...array_map(static fn (int $i): string => "Device {$i}", range(3, 21))], $labels);
        // Device 2 is signed out, device 1 and the other user are not.
        self::assertInstanceOf(InvalidRefreshToken::class, $this->failure(fn () => $this->refreshSession()->execute($sessions[2]->refreshToken)));
        self::assertInstanceOf(Session::class, $this->refreshSession()->execute($kept->refreshToken));
        self::assertInstanceOf(Session::class, $this->refreshSession()->execute($bob->refreshToken));
    }

    public function test_FR_ACC_04_a_wrong_password_and_an_unknown_email_fail_in_the_same_way(): void
    {
        $this->givenUser();

        $wrongPassword = $this->failure(fn () => $this->logIn()->execute(self::email(), 'not the password', null, self::CLIENT));
        $unknownEmail = $this->failure(fn () => $this->logIn()->execute(self::email('nobody@example.org'), self::PASSWORD, null, self::CLIENT));

        self::assertInstanceOf(InvalidCredentials::class, $wrongPassword);
        // What may end up in a log: no address, and not which of the two was wrong.
        self::assertSame('The email address or the password is wrong.', $wrongPassword->getMessage());
        self::assertEquals(
            [$wrongPassword::class, $wrongPassword->getMessage(), $wrongPassword->getCode()],
            [$unknownEmail::class, $unknownEmail->getMessage(), $unknownEmail->getCode()],
        );
        self::assertSame([], $this->refreshTokens->all());
    }

    public function test_NFR_05_a_password_verification_runs_whether_or_not_the_email_is_known(): void
    {
        $this->givenUser();

        $this->failure(fn () => $this->logIn()->execute(self::email(), 'not the password', null, self::CLIENT));
        $this->failure(fn () => $this->logIn()->execute(self::email('nobody@example.org'), 'not the password', null, self::CLIENT));

        // For the unknown email the password is checked against the dummy hash: the same work.
        self::assertSame(
            [
                ['password' => 'not the password', 'hash' => 'fake-v1:' . self::PASSWORD],
                ['password' => 'not the password', 'hash' => FakePasswordHasher::DUMMY_HASH],
            ],
            $this->hasher->verified,
        );
        self::assertSame([], $this->hasher->hashed);
    }

    public function test_NFR_05_nobody_can_log_in_with_the_dummy_hash(): void
    {
        // Even a hasher that would accept a password for the dummy hash must not open a session.
        $this->hasher = new class () extends FakePasswordHasher {
            public function verify(string $password, string $hash): bool
            {
                return true;
            }
        };

        $failure = $this->failure(fn () => $this->logIn()->execute(self::email('nobody@example.org'), 'anything at all', null, self::CLIENT));

        self::assertInstanceOf(InvalidCredentials::class, $failure);
        self::assertSame([], $this->refreshTokens->all());
        self::assertSame(['client' => 1, 'pair' => 1, 'address' => 1], $this->failedLoginsCounted('nobody@example.org', self::CLIENT));
    }

    public function test_FR_ACC_01_an_unconfirmed_account_with_the_right_password_fails_exactly_like_a_wrong_password(): void
    {
        // Otherwise anybody could find out who is registered: register the address with a password
        // of one's own, log in with it, and see whether the answer is the one for a wrong password
        // (a confirmed account was there, and was left alone) or another one.
        $this->givenUser('pending@example.org', verified: false);
        $this->givenUser('confirmed@example.org');

        $pending = $this->failure(fn () => $this->logIn()->execute(self::email('pending@example.org'), self::PASSWORD, null, '198.51.100.1'));
        $wrongPassword = $this->failure(fn () => $this->logIn()->execute(self::email('confirmed@example.org'), 'not the password', null, '198.51.100.2'));
        $unknown = $this->failure(fn () => $this->logIn()->execute(self::email('nobody@example.org'), self::PASSWORD, null, '198.51.100.3'));

        self::assertInstanceOf(InvalidCredentials::class, $pending);
        foreach ([$wrongPassword, $unknown] as $other) {
            self::assertSame(
                [$pending::class, $pending->getMessage(), $pending->getCode()],
                [$other::class, $other->getMessage(), $other->getCode()],
            );
        }
        self::assertSame([], $this->refreshTokens->all());
        // The same work for all three: one password check each, and nothing is hashed.
        self::assertCount(3, $this->hasher->verified);
        self::assertSame([], $this->hasher->hashed);
    }

    public function test_FR_ACC_01_an_unconfirmed_a_confirmed_and_an_unknown_address_behave_identically_at_every_login_limit(): void
    {
        // The count is part of the answer: attempts that fail and then one that is refused, whatever
        // the reason they failed for. An attempt that is taken back for one of the reasons, or an
        // address that is not counted because nobody has it, would show in the refusal which it was.
        $this->givenUser('pending@example.org', verified: false);
        $this->givenUser('confirmed@example.org');
        $attempts = [
            'pending@example.org' => self::PASSWORD,
            'confirmed@example.org' => 'not the password',
            'nobody@example.org' => self::PASSWORD,
        ];

        $answers = [];
        $counted = [];
        foreach (array_keys($attempts) as $index => $address) {
            $password = $attempts[$address];
            $attempt = function (string $client) use ($address, $password): string {
                $failure = $this->failure(fn () => $this->logIn()->execute(self::email($address), $password, null, $client));

                return $failure instanceof RateLimitExceeded ? '429 after ' . $failure->retryAfterSeconds : $failure::class;
            };
            // The limit of the pair: six attempts from one client.
            $stranger = "198.51.{$index}.250";
            for ($i = 1; $i <= 6; $i++) {
                $answers[$address][] = $attempt($stranger);
            }
            // The limit of the address: 45 more from 45 other clients make 50 that count, and
            // the next one, from yet another client, is the 51st.
            for ($i = 1; $i <= 46; $i++) {
                $answers[$address][] = $attempt("198.51.{$index}.{$i}");
            }
            $counted[$address] = [$this->failedLoginsCounted($address, $stranger), $this->failedLoginsCounted($address, "198.51.{$index}.46")];
        }
        // The limit of the client: 30 attempts from one client at six addresses of each kind (five
        // each, so that no pair is over its limit), and then one more.
        $this->clock->advanceSeconds(3600);
        foreach (['pending' => self::PASSWORD, 'confirmed' => 'not the password', 'nobody' => self::PASSWORD] as $kind => $password) {
            $client = '203.0.113.' . strlen($kind);
            for ($i = 0; $i < 31; $i++) {
                $address = "{$kind}-" . ($i % 6) . '@example.org';
                if ($kind !== 'nobody' && $i < 6) {
                    $this->givenUser($address, verified: $kind === 'confirmed');
                }
                $failure = $this->failure(fn () => $this->logIn()->execute(self::email($address), $password, null, $client));
                $answers["from one client: {$kind}"][] = $failure instanceof RateLimitExceeded ? '429 after ' . $failure->retryAfterSeconds : $failure::class;
            }
        }

        $expected = [...array_fill(0, 5, InvalidCredentials::class), '429 after 900', ...array_fill(0, 45, InvalidCredentials::class), '429 after 3600'];
        $expectedFromOneClient = [...array_fill(0, 30, InvalidCredentials::class), '429 after 900'];
        self::assertSame(
            [
                'pending@example.org' => $expected,
                'confirmed@example.org' => $expected,
                'nobody@example.org' => $expected,
                'from one client: pending' => $expectedFromOneClient,
                'from one client: confirmed' => $expectedFromOneClient,
                'from one client: nobody' => $expectedFromOneClient,
            ],
            $answers,
        );
        // And the counters, which later answers depend on, stand alike for all three.
        $expectedCounts = [['client' => 6, 'pair' => 6, 'address' => 51], ['client' => 1, 'pair' => 1, 'address' => 51]];
        self::assertSame(
            ['pending@example.org' => $expectedCounts, 'confirmed@example.org' => $expectedCounts, 'nobody@example.org' => $expectedCounts],
            $counted,
        );
    }

    public function test_FR_ACC_03_the_right_password_for_an_unconfirmed_account_sends_a_fresh_confirmation_link(): void
    {
        // The one who registered and has not confirmed yet gets what they need, by mail, where a
        // stranger cannot see it. The login answer itself says nothing.
        $this->registerUser()->execute(self::registration(locale: Locale::En), '198.51.100.9');
        $this->outbox->clear();
        $this->clock->advanceSeconds(1800);

        $this->failure(fn () => $this->logIn()->execute(self::email('Anna@Example.org'), self::PASSWORD, null, self::CLIENT));

        $mail = $this->theMail();
        self::assertSame('anna@example.org', $mail->to->value);
        self::assertSame('confirm_email [en]', $mail->subject);
        self::assertSame('confirmUrl=https://app.example.test/verify-email?token=token-2', $mail->text);
        // Only the newest link works, and the 24 hours to confirm start again with it.
        self::assertFalse($this->hasOneTimeToken('token-1', TokenPurpose::VerifyEmail));
        self::assertCount(1, $this->oneTimeTokens->all());
        self::assertEquals(new DateTimeImmutable('2026-11-18 12:30:00 UTC'), $this->oneTimeTokens->all()[0]->expiresAt);
        self::assertEquals(new DateTimeImmutable('2026-11-17 12:30:00 UTC'), $this->users->all()[0]->createdAt);
        self::assertFalse($this->users->all()[0]->isVerified());
        // Counted under two rules of its own, which nothing else reads (see the next tests).
        self::assertSame(1, $this->hits(AuthLimits::confirmationMailsByLoginPerClient(), self::CLIENT));
        self::assertSame(1, $this->hits(AuthLimits::confirmationMailsByLoginPerEmail(), 'anna@example.org'));
    }

    public function test_FR_ACC_03_the_confirmation_mail_of_a_login_is_queued_with_its_link_and_not_sent_by_the_request(): void
    {
        $this->registerUser()->execute(self::registration(), '198.51.100.9');
        $this->outbox->clear();
        $this->clock->advanceSeconds(1800);

        $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT));

        $queued = $this->outbox->all();
        self::assertCount(1, $queued);
        self::assertSame('confirm_email', $queued[0]->kind->value);
        self::assertTrue($queued[0]->userId->equals($this->users->all()[0]->id));
        self::assertEquals($this->now(), $queued[0]->createdAt);
        self::assertEquals($this->now(), $queued[0]->notBefore);
        self::assertEquals($this->oneTimeTokens->all()[0]->expiresAt, $queued[0]->expiresAt());
        self::assertStringNotContainsString('token-2', serialize($queued[0]));
    }

    public function test_FR_ACC_03_a_new_link_of_a_login_whose_mail_cannot_be_queued_does_not_replace_the_one_that_was_mailed(): void
    {
        $this->registerUser()->execute(self::registration(), '198.51.100.9');
        $before = [$this->users->all(), $this->oneTimeTokens->all(), $this->outbox->all()];
        $this->clock->advanceSeconds(1800);
        $this->outbox->failNextAdds = 1;

        $failure = $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT));

        // The mail is queued in the transaction that stores the new link, so the link of the mail
        // that did go out keeps working.
        self::assertSame('The mail could not be queued.', $failure->getMessage());
        self::assertEquals($before, [$this->users->all(), $this->oneTimeTokens->all(), $this->outbox->all()]);
        self::assertTrue($this->hasOneTimeToken('token-1', TokenPurpose::VerifyEmail));
    }

    public function test_FR_ACC_01_the_mail_of_a_login_does_not_touch_the_limits_that_other_requests_answer_429_for(): void
    {
        // Registration, "send the link again" and the reset request refuse with 429 when the address
        // or the client had too many mails. If a login counted towards those limits, but only a
        // login with the right password for an unconfirmed registration, then that 429 would say
        // which kind of login it was: the very thing the login answer hides.
        $this->givenUser('pending@example.org', verified: false);
        $this->givenUser('confirmed@example.org');

        for ($i = 1; $i <= 4; $i++) {
            $this->failure(fn () => $this->logIn()->execute(self::email('pending@example.org'), self::PASSWORD, null, '198.51.100.1'));
            $this->failure(fn () => $this->logIn()->execute(self::email('confirmed@example.org'), 'not the password', null, '198.51.100.2'));
        }

        foreach (['pending@example.org' => '198.51.100.1', 'confirmed@example.org' => '198.51.100.2'] as $address => $client) {
            self::assertSame(0, $this->hits(AuthLimits::mailsPerAddress(), $address));
            self::assertSame(0, $this->hits(AuthLimits::mailsPerPair(), $address, $client));
            self::assertSame(0, $this->hits(AuthLimits::mailsPerClient(), $client));
            // So what comes next is answered alike for both.
            $this->resendVerification()->execute(self::email($address), $client);
            self::assertSame(1, $this->hits(AuthLimits::mailsPerAddress(), $address));
        }
    }

    public function test_FR_ACC_03_a_wrong_password_for_an_unconfirmed_account_sends_nothing(): void
    {
        $this->givenUser(verified: false);

        $this->failure(fn () => $this->logIn()->execute(self::email(), 'not the password', null, self::CLIENT));

        self::assertSame([], $this->queuedMails());
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertSame(0, $this->hits(AuthLimits::confirmationMailsByLoginPerClient(), self::CLIENT));
        self::assertSame(0, $this->hits(AuthLimits::confirmationMailsByLoginPerEmail(), 'anna@example.org'));
        self::assertSame(0, $this->transactions->commits);
    }

    public function test_NFR_05_a_login_mails_at_most_one_confirmation_link_per_hour_to_an_address_and_stays_silent_after_that(): void
    {
        $this->givenUser(verified: false);
        $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, '198.51.100.1'));
        self::assertCount(1, $this->queuedMails());
        $this->outbox->clear();
        $tokens = $this->oneTimeTokens->all();
        $users = $this->users->all();
        $this->clock->advanceSeconds(3599);

        // From another client, so that only the limit of the address is at work.
        $failure = $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, '198.51.100.2'));

        // The same answer, and no 429: that would be an answer of its own.
        self::assertInstanceOf(InvalidCredentials::class, $failure);
        self::assertSame([], $this->queuedMails());
        self::assertEquals($tokens, $this->oneTimeTokens->all());
        self::assertEquals($users, $this->users->all());

        // In the next hour there is one again.
        $this->clock->advanceSeconds(1);
        $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, '198.51.100.3'));
        self::assertCount(1, $this->queuedMails());
    }

    public function test_NFR_05_one_client_causes_at_most_5_confirmation_mails_per_hour_by_logging_in(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->givenUser("pending{$i}@example.org", verified: false);
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->failure(fn () => $this->logIn()->execute(self::email("pending{$i}@example.org"), self::PASSWORD, null, self::CLIENT));
        }
        $failure = $this->failure(fn () => $this->logIn()->execute(self::email('pending6@example.org'), self::PASSWORD, null, self::CLIENT));

        self::assertInstanceOf(InvalidCredentials::class, $failure);
        self::assertCount(5, $this->queuedMails());
        self::assertCount(5, $this->oneTimeTokens->all());
        // The client first, as everywhere: a client over its limit does not use up the one mail of an address.
        self::assertSame(0, $this->hits(AuthLimits::confirmationMailsByLoginPerEmail(), 'pending6@example.org'));
        self::assertSame(1, $this->hits(AuthLimits::confirmationMailsByLoginPerEmail(), 'pending5@example.org'));
    }

    public function test_FR_ACC_03_a_registration_older_than_24_hours_gets_no_link_by_logging_in(): void
    {
        // It is to be deleted, whether or not the cleanup has come by yet.
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->outbox->clear();
        $this->clock->advanceSeconds(86_400);
        $before = $this->users->all();

        $failure = $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT));

        self::assertInstanceOf(InvalidCredentials::class, $failure);
        self::assertSame([], $this->queuedMails());
        self::assertEquals($before, $this->users->all());
        self::assertTrue($this->hasOneTimeToken('token-1', TokenPurpose::VerifyEmail));
        self::assertSame(0, $this->hits(AuthLimits::confirmationMailsByLoginPerEmail(), 'anna@example.org'));
        self::assertSame(0, $this->hits(AuthLimits::confirmationMailsByLoginPerClient(), self::CLIENT));

        // One second earlier it still got one.
        $this->clock->travelTo($this->now()->modify('-1 second'));
        $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, '198.51.100.1'));
        self::assertCount(1, $this->queuedMails());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function changesWhileThePasswordIsChecked(): iterable
    {
        yield 'confirmed by the link' => ['confirm'];
        yield 'replaced by another registration' => ['replace'];
        yield 'deleted' => ['delete'];
    }

    #[DataProvider('changesWhileThePasswordIsChecked')]
    public function test_FR_ACC_03_no_link_is_mailed_for_a_registration_that_changed_while_the_password_was_checked(string $change): void
    {
        $user = $this->givenUser(verified: false);
        $this->hasher = new class ($this->users, $user, $change, $this->now()) extends FakePasswordHasher {
            public function __construct(
                private readonly InMemoryUserRepository $users,
                private readonly User $user,
                private readonly string $change,
                private readonly DateTimeImmutable $now,
            ) {
            }

            public function verify(string $password, string $hash): bool
            {
                $correct = parent::verify($password, $hash);
                match ($this->change) {
                    'confirm' => $this->users->update($this->user->verifiedAt($this->now)),
                    'replace' => $this->users->update($this->user->withPasswordHash('fake-v1:somebody elses password')),
                    default => $this->users->delete($this->user->id),
                };

                return $correct;
            }
        };

        $failure = $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT));

        self::assertInstanceOf(InvalidCredentials::class, $failure);
        self::assertSame([], $this->queuedMails());
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertSame([], $this->refreshTokens->all());
    }

    public function test_FR_ACC_03_an_unconfirmed_account_with_a_wrong_password_fails_like_any_other(): void
    {
        $this->givenUser(verified: false);

        $failure = $this->failure(fn () => $this->logIn()->execute(self::email(), 'not the password', null, self::CLIENT));

        // Not "confirm your email": that would tell a stranger that the registration exists.
        self::assertInstanceOf(InvalidCredentials::class, $failure);
        self::assertSame(['client' => 1, 'pair' => 1, 'address' => 1], $this->failedLoginsCounted('anna@example.org', self::CLIENT));
    }

    public function test_NFR_05_the_password_is_checked_exactly_as_typed(): void
    {
        $this->givenUser(password: ' with space around ');

        self::assertInstanceOf(
            InvalidCredentials::class,
            $this->failure(fn () => $this->logIn()->execute(self::email(), 'with space around', null, self::CLIENT)),
        );
        self::assertInstanceOf(Session::class, $this->logIn()->execute(self::email(), ' with space around ', null, self::CLIENT));
        self::assertSame(' with space around ', $this->hasher->verified[1]['password']);
    }

    public function test_NFR_05_a_hash_with_outdated_parameters_is_replaced_on_a_successful_login(): void
    {
        $user = $this->givenUser();
        $this->hasher->version = 2;

        $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);

        self::assertSame('fake-v2:' . self::PASSWORD, $this->users->all()[0]->passwordHash);
        self::assertSame([self::PASSWORD], $this->hasher->hashed);
        // Nothing else about the account changed.
        self::assertEquals($user->withPasswordHash('fake-v2:' . self::PASSWORD), $this->users->all()[0]);

        // The new hash works, and is not replaced again.
        $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);
        self::assertSame([self::PASSWORD], $this->hasher->hashed);
    }

    public function test_NFR_05_a_current_hash_is_left_alone(): void
    {
        $user = $this->givenUser();

        $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);

        self::assertSame([], $this->hasher->hashed);
        self::assertEquals([$user], $this->users->all());
    }

    public function test_NFR_05_a_failed_login_never_replaces_the_hash(): void
    {
        $user = $this->givenUser();
        $this->hasher->version = 2;

        $this->failure(fn () => $this->logIn()->execute(self::email(), 'not the password', null, self::CLIENT));

        self::assertEquals([$user], $this->users->all());
        self::assertSame([], $this->hasher->hashed);
    }

    public function test_NFR_05_five_wrong_logins_lock_the_one_who_guesses_and_not_the_account(): void
    {
        // SRS OQ-11: a stranger must not be able to keep the owner out of the account.
        $stranger = '198.51.100.66';
        $this->givenUser();
        $this->givenUser('bob@example.org');
        for ($i = 1; $i <= 5; $i++) {
            self::assertInstanceOf(InvalidCredentials::class, $this->failure(fn () => $this->logIn()->execute(self::email(), "guess {$i}", null, $stranger)));
        }
        $this->clock->advanceSeconds(899);

        // The owner, at another client address, logs in with the right password.
        self::assertInstanceOf(Session::class, $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT));

        // The stranger's client is locked for that address: even with the right password, and
        // before the password is looked at.
        $refused = $this->failure(fn () => $this->logIn()->execute(self::email('Anna@Example.org'), self::PASSWORD, null, $stranger));
        self::assertInstanceOf(RateLimitExceeded::class, $refused);
        self::assertSame(1, $refused->retryAfterSeconds);
        self::assertCount(6, $this->hasher->verified);
        self::assertCount(1, $this->refreshTokens->all());
        // Only for that address: the same client still logs in to another account.
        self::assertInstanceOf(Session::class, $this->logIn()->execute(self::email('bob@example.org'), self::PASSWORD, null, $stranger));
        // And only until the quarter of an hour is over.
        $this->clock->advanceSeconds(1);
        self::assertInstanceOf(Session::class, $this->logIn()->execute(self::email(), self::PASSWORD, null, $stranger));
    }

    public function test_NFR_05_the_31st_failed_login_from_a_client_address_within_15_minutes_is_refused(): void
    {
        $this->givenUser();
        // Against six addresses, known or not, five times each: the limit is the client's.
        for ($i = 0; $i < 30; $i++) {
            $this->failure(fn () => $this->logIn()->execute(self::email('user' . ($i % 6) . '@example.org'), 'not the password', null, self::CLIENT));
        }

        $refused = $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT));

        self::assertInstanceOf(RateLimitExceeded::class, $refused);
        self::assertSame(900, $refused->retryAfterSeconds);
        self::assertCount(30, $this->hasher->verified);
        // The refused attempt is not held against the address it named: a client over its own
        // limit uses up nobody else's budget.
        self::assertSame(['client' => 31, 'pair' => 0, 'address' => 0], $this->failedLoginsCounted('anna@example.org', self::CLIENT));

        // Another client address can still log in.
        self::assertInstanceOf(Session::class, $this->logIn()->execute(self::email(), self::PASSWORD, null, '198.51.100.1'));
    }

    public function test_NFR_05_the_51st_failed_login_for_an_address_within_an_hour_locks_it_for_everybody(): void
    {
        $this->givenUser();
        $this->givenUser('bob@example.org');
        // From fifty client addresses: no client and no pair is anywhere near its own limit.
        for ($i = 1; $i <= 50; $i++) {
            self::assertInstanceOf(InvalidCredentials::class, $this->failure(fn () => $this->logIn()->execute(self::email(), 'not the password', null, "198.51.100.{$i}")));
        }
        $this->clock->advanceSeconds(3599);

        // Even with the right password, from a client that never tried, and before the password is looked at.
        $refused = $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT));

        self::assertInstanceOf(RateLimitExceeded::class, $refused);
        self::assertSame(1, $refused->retryAfterSeconds);
        self::assertCount(50, $this->hasher->verified);
        self::assertSame([], $this->refreshTokens->all());

        // Another account is not affected, and after the hour the address works again.
        $this->logIn()->execute(self::email('bob@example.org'), self::PASSWORD, null, self::CLIENT);
        $this->clock->advanceSeconds(1);
        $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);
        self::assertCount(2, $this->refreshTokens->all());
    }

    public function test_NFR_05_a_successful_login_does_not_forgive_earlier_failures(): void
    {
        $this->givenUser('victim@example.org');
        $this->givenUser('attacker@example.org', 'the attackers own password');

        // Four guesses at the victim, then a login to the attacker's own account, and again.
        for ($i = 1; $i <= 4; $i++) {
            $this->failure(fn () => $this->logIn()->execute(self::email('victim@example.org'), "guess {$i}", null, self::CLIENT));
        }
        $this->logIn()->execute(self::email('attacker@example.org'), 'the attackers own password', null, self::CLIENT);

        self::assertSame(['client' => 4, 'pair' => 4, 'address' => 4], $this->failedLoginsCounted('victim@example.org', self::CLIENT));
        self::assertSame(['client' => 4, 'pair' => 0, 'address' => 0], $this->failedLoginsCounted('attacker@example.org', self::CLIENT));

        $this->failure(fn () => $this->logIn()->execute(self::email('victim@example.org'), 'guess 5', null, self::CLIENT));
        $this->logIn()->execute(self::email('attacker@example.org'), 'the attackers own password', null, '198.51.100.1');

        // The sixth guess is refused: the logins in between bought nothing.
        self::assertInstanceOf(
            RateLimitExceeded::class,
            $this->failure(fn () => $this->logIn()->execute(self::email('victim@example.org'), 'guess 6', null, self::CLIENT)),
        );
        // And the victim's own successful logins from elsewhere do not reset the count of the address either.
        $this->logIn()->execute(self::email('victim@example.org'), self::PASSWORD, null, '198.51.100.2');
        self::assertSame(5, $this->hits(AuthLimits::loginFailuresPerAddress(), 'victim@example.org'));
        self::assertSame(6, $this->hits(AuthLimits::loginFailuresPerPair(), 'victim@example.org', self::CLIENT));
    }

    public function test_NFR_05_only_failures_count_so_a_user_can_log_in_as_often_as_they_like(): void
    {
        $this->givenUser();

        for ($i = 1; $i <= 12; $i++) {
            $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);
        }

        self::assertCount(12, $this->refreshTokens->all());
        $this->assertNothingCounted();
    }

    public function test_NFR_05_a_password_that_was_changed_while_it_was_being_checked_gives_no_session(): void
    {
        $user = $this->givenUser();
        // Between reading the account and writing the session, a password reset went through.
        $this->hasher = new class ($this->users, $user) extends FakePasswordHasher {
            public function __construct(private readonly UserRepository $users, private readonly User $user)
            {
            }

            public function verify(string $password, string $hash): bool
            {
                $correct = parent::verify($password, $hash);
                $this->users->update($this->user->withPasswordHash('fake-v1:a new password'));

                return $correct;
            }
        };

        $failure = $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT));

        self::assertInstanceOf(InvalidCredentials::class, $failure);
        self::assertSame([], $this->refreshTokens->all());
        self::assertSame('fake-v1:a new password', $this->users->all()[0]->passwordHash);
        self::assertSame(['client' => 1, 'pair' => 1, 'address' => 1], $this->failedLoginsCounted('anna@example.org', self::CLIENT));
    }

    public function test_an_account_that_was_deleted_while_the_password_was_being_checked_gives_no_session(): void
    {
        $user = $this->givenUser();
        $this->hasher = new class ($this->users, $user) extends FakePasswordHasher {
            public function __construct(private readonly InMemoryUserRepository $users, private readonly User $user)
            {
            }

            public function verify(string $password, string $hash): bool
            {
                $correct = parent::verify($password, $hash);
                $this->users->delete($this->user->id);

                return $correct;
            }
        };

        $failure = $this->failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT));

        self::assertInstanceOf(InvalidCredentials::class, $failure);
        self::assertSame([], $this->refreshTokens->all());
    }

    /**
     * @param callable(): mixed $attempt
     */
    private function failure(callable $attempt): Throwable
    {
        try {
            $attempt();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('Expected the attempt to fail.');
    }
}
