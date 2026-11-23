<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\AuthLimits;
use SmokeDetector\Application\Auth\DeviceLabel;
use SmokeDetector\Application\Auth\InvalidCredentials;
use SmokeDetector\Application\Auth\InvalidToken;
use SmokeDetector\Application\Auth\OneTimeToken;
use SmokeDetector\Application\Auth\OneTimeTokens;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Tests\Support\Fake\FakePasswordHasher;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Support\InMemory\InMemoryOneTimeTokenRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryRateLimitCounters;
use SmokeDetector\Tests\Support\InMemory\InMemoryUserRepository;
use SmokeDetector\Tests\Support\RateLimitKey;
use Throwable;

/**
 * POST /auth/verify-email (HLD §4.5 "Registration" step 5, FR-ACC-03): the token of the link
 * together with the password of the sign-up confirms the account and starts a session.
 */
final class VerifyEmailTest extends AuthUseCaseTestCase
{
    public function test_FR_ACC_03_the_link_and_the_password_of_the_sign_up_confirm_the_account(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->clock->advanceSeconds(3600);

        $this->confirm('token-1');

        $user = $this->users->all()[0];
        self::assertTrue($user->isVerified());
        self::assertEquals(new DateTimeImmutable('2026-11-17 13:00:00 UTC'), $user->emailVerifiedAt);
        // Nothing else about the account changed.
        self::assertSame('fake-v1:' . self::PASSWORD, $user->passwordHash);
        self::assertEquals(new DateTimeImmutable('2026-11-17 12:00:00 UTC'), $user->createdAt);
        self::assertSame(0, $user->tokenVersion);
    }

    public function test_FR_ACC_03_confirming_starts_a_session_exactly_like_a_login(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->clock->advanceSeconds(3600);

        $session = $this->verifyEmail()->execute('token-1', self::PASSWORD, DeviceLabel::fromInput('Annas iPhone'), self::CLIENT);

        $user = $this->users->all()[0];
        // token-1 is the link, token-2 the key of the session.
        self::assertSame('access-1-v0-for-' . $user->id->value, $session->accessToken->token);
        self::assertStringStartsWith('token-2.', $session->refreshToken);
        self::assertEquals(new DateTimeImmutable('2027-01-16 13:00:00 UTC'), $session->refreshTokenExpiresAt);
        $stored = $this->refreshTokens->all();
        self::assertCount(1, $stored);
        self::assertTrue($stored[0]->userId->equals($user->id));
        self::assertSame(self::hashOf($session->refreshToken), $stored[0]->tokenHash);
        self::assertSame('Annas iPhone', $stored[0]->deviceLabel?->value);
        self::assertEquals($this->now(), $stored[0]->createdAt);
        // And it is the session of a confirmed account: the access token is accepted.
        self::assertTrue($this->authenticate()->execute($session->accessToken->token)->equals($user->id));
    }

    public function test_FR_ACC_03_a_wrong_password_confirms_nothing_and_gives_nobody_a_session(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $before = $this->users->all();

        $refused = $this->failure(fn () => $this->confirm('token-1', 'not the password'));

        // The answer of a failed login, and it says nothing about the registration.
        self::assertInstanceOf(InvalidCredentials::class, $refused);
        self::assertSame('The email address or the password is wrong.', $refused->getMessage());
        self::assertEquals($before, $this->users->all());
        self::assertFalse($this->users->all()[0]->isVerified());
        self::assertSame([], $this->refreshTokens->all());
        // The password was checked against the hash of the pending registration, exactly as typed.
        self::assertSame([['password' => 'not the password', 'hash' => 'fake-v1:' . self::PASSWORD]], $this->hasher->verified);
    }

    public function test_FR_ACC_03_the_link_stays_usable_after_a_wrong_password(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);

        $this->failure(fn () => $this->confirm('token-1', 'not the password'));

        $token = $this->oneTimeTokens->all()[0];
        self::assertNull($token->usedAt);
        self::assertSame(1, $token->failedAttempts);
        $this->confirm('token-1');
        self::assertTrue($this->users->all()[0]->isVerified());
        self::assertEquals($this->now(), $this->oneTimeTokens->all()[0]->usedAt);
    }

    public function test_NFR_05_after_five_wrong_passwords_the_link_is_deleted_and_answers_like_an_unknown_one(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);

        for ($i = 1; $i <= 5; $i++) {
            self::assertSame($i - 1, $this->oneTimeTokens->all()[0]->failedAttempts);
            // From a new client each time: the five are the link's, whoever tries.
            self::assertInstanceOf(InvalidCredentials::class, $this->failure(fn () => $this->confirm('token-1', "guess {$i}", "198.51.100.{$i}")));
        }

        self::assertSame([], $this->oneTimeTokens->all());
        // Also with the right password, and without looking at it.
        self::assertInstanceOf(InvalidToken::class, $this->failure(fn () => $this->confirm('token-1', self::PASSWORD, '198.51.100.6')));
        self::assertCount(5, $this->hasher->verified);
        self::assertFalse($this->users->all()[0]->isVerified());
        self::assertSame([], $this->refreshTokens->all());
    }

    public function test_NFR_05_the_fifth_try_still_confirms_with_the_right_password(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        for ($i = 1; $i <= 4; $i++) {
            $this->failure(fn () => $this->confirm('token-1', "guess {$i}", "198.51.100.{$i}"));
        }
        self::assertSame(4, $this->oneTimeTokens->all()[0]->failedAttempts);

        $this->confirm('token-1');

        self::assertTrue($this->users->all()[0]->isVerified());
        self::assertCount(1, $this->refreshTokens->all());
        self::assertNotNull($this->oneTimeTokens->all()[0]->usedAt);
    }

    public function test_NFR_05_a_link_with_five_tries_on_it_is_refused_even_before_it_is_deleted(): void
    {
        // The fifth try may still be checking its password when the sixth arrives.
        $user = $this->givenUser(verified: false);
        $this->givenToken($user, 'worn-out', failedAttempts: OneTimeTokens::MAX_FAILED_ATTEMPTS);

        self::assertInstanceOf(InvalidToken::class, $this->failure(fn () => $this->confirm('worn-out')));

        self::assertSame([], $this->hasher->verified);
        self::assertSame(OneTimeTokens::MAX_FAILED_ATTEMPTS, $this->oneTimeTokens->all()[0]->failedAttempts);
        $this->assertNothingCounted();
    }

    public function test_NFR_05_a_try_is_counted_on_the_link_before_the_password_is_looked_at(): void
    {
        // Tries that arrive at the same moment cannot outrun the counter: when the password of the
        // first is being checked, its try is already stored.
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $hasher = new class ($this->oneTimeTokens) extends FakePasswordHasher {
            /** @var list<int> The failed attempts stored on the link each time a password was checked. */
            public array $seen = [];

            public function __construct(private readonly InMemoryOneTimeTokenRepository $tokens)
            {
            }

            public function verify(string $password, string $hash): bool
            {
                $this->seen[] = $this->tokens->all()[0]->failedAttempts;

                return parent::verify($password, $hash);
            }
        };
        $this->hasher = $hasher;

        $this->failure(fn () => $this->confirm('token-1', 'not the password'));

        self::assertSame([1], $hasher->seen);
    }

    public function test_FR_ACC_03_a_new_link_starts_with_five_tries_again(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        for ($i = 1; $i <= 3; $i++) {
            $this->failure(fn () => $this->confirm('token-1', "guess {$i}"));
        }

        $this->resendVerification()->execute(self::email(), '198.51.100.9');

        self::assertSame(0, $this->oneTimeTokens->all()[0]->failedAttempts);
        self::assertInstanceOf(InvalidToken::class, $this->failure(fn () => $this->confirm('token-1')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function worthlessTokens(): iterable
    {
        yield 'unknown' => ['token-9'];
        yield 'empty' => [''];
        yield 'the hash of the token' => [hash('sha256', 'token-1')];
        yield 'used' => ['used'];
        yield 'expired' => ['expired'];
        yield 'of an account that is gone' => ['orphan'];
        yield 'of a registration older than 24 hours' => ['late'];
        yield 'for a password reset' => ['reset-token'];
    }

    #[DataProvider('worthlessTokens')]
    public function test_NFR_05_a_token_that_is_worth_nothing_is_refused_before_any_password_is_hashed_or_anything_is_counted(string $token): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->forgetWhatWasCounted();
        $used = $this->givenUser('used@example.org', verified: false);
        $this->givenToken($used, 'used', usedAt: $this->now()->modify('-1 minute'));
        $expired = $this->givenUser('expired@example.org', verified: false);
        $this->givenToken($expired, 'expired', expiresAt: $this->now());
        $this->oneTimeTokens->put(new OneTimeToken(SequentialIdGenerator::id(900), UserId::fromString(SequentialIdGenerator::id(901)), TokenPurpose::VerifyEmail, hash('sha256', 'orphan'), $this->now()->modify('+1 hour'), null));
        $late = $this->givenUser('late@example.org', verified: false);
        $this->users->update($late->registeredAt($this->now()->modify('-24 hours')));
        $this->givenToken($late, 'late');
        $reset = $this->givenUser('reset@example.org', verified: false);
        $this->givenToken($reset, 'reset-token', TokenPurpose::ResetPassword);
        $tokens = $this->oneTimeTokens->all();
        $users = $this->users->all();
        $transactions = $this->transactions->commits + $this->transactions->rollbacks;

        $refused = $this->failure(fn () => $this->confirm($token));

        self::assertInstanceOf(InvalidToken::class, $refused);
        // The same for every reason.
        self::assertSame('The token is invalid, expired or already used.', $refused->getMessage());
        // So the endpoint cannot be used to make the server hash passwords, or to use up a limit.
        self::assertSame([], $this->hasher->verified);
        $this->assertNothingCounted();
        self::assertEquals($tokens, $this->oneTimeTokens->all());
        self::assertEquals($users, $this->users->all());
        self::assertSame([], $this->refreshTokens->all());
        // Found out by reading alone: no transaction, no lock.
        self::assertSame($transactions, $this->transactions->commits + $this->transactions->rollbacks);
    }

    public function test_FR_ACC_03_a_link_works_only_once(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->confirm('token-1');
        $verifiedAt = $this->users->all()[0]->emailVerifiedAt;
        $this->clock->advanceSeconds(60);

        self::assertInstanceOf(InvalidToken::class, $this->failure(fn () => $this->confirm('token-1')));

        self::assertEquals($this->now()->modify('-60 seconds'), $this->oneTimeTokens->all()[0]->usedAt);
        self::assertSame($verifiedAt, $this->users->all()[0]->emailVerifiedAt);
        self::assertCount(1, $this->refreshTokens->all());
    }

    public function test_FR_ACC_03_a_link_works_until_just_before_24_hours_are_over(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->clock->advanceSeconds(86_399);

        $this->confirm('token-1');

        self::assertTrue($this->users->all()[0]->isVerified());
    }

    public function test_FR_ACC_03_a_link_expires_after_24_hours(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->clock->advanceSeconds(86_400);

        self::assertInstanceOf(InvalidToken::class, $this->failure(fn () => $this->confirm('token-1')));

        self::assertFalse($this->users->all()[0]->isVerified());
        self::assertNull($this->oneTimeTokens->all()[0]->usedAt);
    }

    public function test_FR_ACC_03_a_registration_older_than_24_hours_is_not_confirmed_even_with_a_token_that_still_works(): void
    {
        // The rule is the registration's own age, so that it does not hang on when the cleanup runs
        // or on how a token came about.
        $user = $this->givenUser(verified: false);
        $this->clock->travelTo($user->createdAt->modify('+24 hours'));
        $this->givenToken($user, 'late');

        self::assertInstanceOf(InvalidToken::class, $this->failure(fn () => $this->confirm('late')));

        self::assertFalse($this->users->all()[0]->isVerified());
        self::assertNull($this->oneTimeTokens->all()[0]->usedAt);

        // One second earlier it is.
        $this->clock->travelTo($this->now()->modify('-1 second'));
        $this->confirm('late');
        self::assertTrue($this->users->all()[0]->isVerified());
    }

    public function test_FR_ACC_01_the_link_of_a_replaced_registration_is_refused_and_the_newest_one_needs_the_newest_password(): void
    {
        $this->registerUser()->execute(self::registration(password: 'the first password'), self::CLIENT);
        $this->registerUser()->execute(self::registration(password: 'the second password'), self::CLIENT);

        self::assertInstanceOf(InvalidToken::class, $this->failure(fn () => $this->confirm('token-1', 'the first password')));
        self::assertInstanceOf(InvalidCredentials::class, $this->failure(fn () => $this->confirm('token-2', 'the first password')));
        self::assertFalse($this->users->all()[0]->isVerified());

        $this->confirm('token-2', 'the second password');
        self::assertTrue($this->users->all()[0]->isVerified());
        self::assertSame('fake-v1:the second password', $this->users->all()[0]->passwordHash);
    }

    public function test_FR_ACC_01_a_stranger_who_registers_the_address_last_cannot_take_the_account_over(): void
    {
        // The attack of the step 3 security review (SRS OQ-10): the attacker registers the address,
        // the owner registers, the attacker registers again. The newest link, in the owner's
        // mailbox, now belongs to the attacker's submission and its password.
        $attacker = '198.51.100.66';
        $this->registerUser()->execute(self::registration(password: 'the attackers password'), $attacker);
        $this->registerUser()->execute(self::registration(password: 'the owners password'), self::CLIENT);
        $this->clock->advanceSeconds(3600);
        $this->registerUser()->execute(self::registration(password: 'the attackers password'), $attacker);
        $newest = $this->tokens->last();

        // The owner clicks the newest link and types the password they chose.
        $refused = $this->failure(fn () => $this->confirm($newest, 'the owners password'));

        self::assertInstanceOf(InvalidCredentials::class, $refused);
        self::assertFalse($this->users->all()[0]->isVerified());
        self::assertSame([], $this->refreshTokens->all());
        // The attacker's password logs nobody in: the account was never activated.
        self::assertInstanceOf(InvalidCredentials::class, $this->failure(fn () => $this->logIn()->execute(self::email(), 'the attackers password', null, $attacker)));
        self::assertSame([], $this->refreshTokens->all());

        // The owner signs up again and confirms with their own password.
        $this->clock->advanceSeconds(3600);
        $this->registerUser()->execute(self::registration(password: 'the owners password'), self::CLIENT);
        $session = $this->confirm($this->tokens->last(), 'the owners password');

        $user = $this->users->all()[0];
        self::assertTrue($user->isVerified());
        self::assertSame('fake-v1:the owners password', $user->passwordHash);
        self::assertTrue($this->authenticate()->execute($session->accessToken->token)->equals($user->id));
        self::assertInstanceOf(InvalidCredentials::class, $this->failure(fn () => $this->logIn()->execute(self::email(), 'the attackers password', null, '198.51.100.67')));
        self::assertCount(1, $this->refreshTokens->all());
    }

    public function test_an_account_that_is_confirmed_already_is_not_logged_in_by_a_confirmation_link(): void
    {
        // A link confirms a registration that is waiting. It is no second way to log in.
        $user = $this->givenUser(verified: true);
        $this->givenToken($user, 'late');

        self::assertInstanceOf(InvalidToken::class, $this->failure(fn () => $this->confirm('late')));

        self::assertEquals($user, $this->users->all()[0]);
        self::assertSame([], $this->refreshTokens->all());
        self::assertNull($this->oneTimeTokens->all()[0]->usedAt);
    }

    public function test_NFR_05_a_wrong_password_counts_as_a_failed_login_of_the_client_only(): void
    {
        $this->registerUser()->execute(self::registration(), '198.51.100.9');
        $this->forgetWhatWasCounted();

        $this->failure(fn () => $this->confirm('token-1', 'not the password'));

        self::assertSame(1, $this->hits(AuthLimits::loginFailuresPerClient(), self::CLIENT));
        // Nothing is counted for the address: the link has its own five tries.
        self::assertSame([RateLimitKey::of(AuthLimits::loginFailuresPerClient()->name, self::CLIENT)], array_keys($this->counters->all()));
    }

    public function test_NFR_05_confirming_with_the_right_password_is_not_a_failed_login(): void
    {
        $this->registerUser()->execute(self::registration(), '198.51.100.9');
        $this->forgetWhatWasCounted();

        $this->confirm('token-1');

        $this->assertNothingCounted();
    }

    public function test_NFR_05_a_client_over_its_login_limit_is_refused_without_a_try_on_the_link(): void
    {
        $this->registerUser()->execute(self::registration(), '198.51.100.9');
        $limit = AuthLimits::loginFailuresPerClient();
        $limiter = new RateLimiter($this->counters, RateLimitKey::hasher());
        for ($i = 1; $i <= $limit->maxHits; $i++) {
            $limiter->consume($limit, self::CLIENT, $this->now());
        }

        // Even with the right password, and before it is looked at.
        $refused = $this->failure(fn () => $this->confirm('token-1'));

        self::assertInstanceOf(RateLimitExceeded::class, $refused);
        self::assertSame($limit->windowSeconds, $refused->retryAfterSeconds);
        self::assertSame([], $this->hasher->verified);
        self::assertSame(0, $this->oneTimeTokens->all()[0]->failedAttempts);
        self::assertFalse($this->users->all()[0]->isVerified());
        // Another client confirms with the same link.
        $this->confirm('token-1', client: '198.51.100.1');
        self::assertTrue($this->users->all()[0]->isVerified());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function changesWhileThePasswordIsChecked(): iterable
    {
        yield 'the registration was submitted again' => ['replace'];
        yield 'another request confirmed the account' => ['confirm'];
        yield 'the registration was deleted' => ['delete'];
    }

    #[DataProvider('changesWhileThePasswordIsChecked')]
    public function test_a_registration_that_changed_while_its_password_was_being_checked_is_not_confirmed(string $change): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $user = $this->users->all()[0];
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
                    'replace' => $this->users->update($this->user->withPasswordHash('fake-v1:somebody elses password')),
                    'confirm' => $this->users->update($this->user->verifiedAt($this->now)),
                    default => $this->users->delete($this->user->id),
                };

                return $correct;
            }
        };

        $refused = $this->failure(fn () => $this->confirm('token-1'));

        // The password that was right belongs to a registration that is not there any more.
        self::assertInstanceOf(InvalidToken::class, $refused);
        self::assertSame([], $this->refreshTokens->all());
        self::assertNull($this->oneTimeTokens->all()[0]->usedAt);
        if ($change === 'replace') {
            self::assertFalse($this->users->all()[0]->isVerified());
        }
        // Not taken back: only a confirmation is.
        self::assertSame(1, $this->hits(AuthLimits::loginFailuresPerClient(), self::CLIENT));
    }

    public function test_a_link_that_was_replaced_while_a_wrong_password_was_being_checked_is_left_alone(): void
    {
        // The fifth wrong password deletes the link it was tried on, never the one that was mailed
        // in the meantime (which lives in the same row).
        $user = $this->givenUser(verified: false);
        $this->givenToken($user, 'old-link', failedAttempts: OneTimeTokens::MAX_FAILED_ATTEMPTS - 1);
        $this->hasher = new class ($this->oneTimeTokens, $user, $this->now()) extends FakePasswordHasher {
            public function __construct(
                private readonly InMemoryOneTimeTokenRepository $tokens,
                private readonly User $user,
                private readonly DateTimeImmutable $now,
            ) {
            }

            public function verify(string $password, string $hash): bool
            {
                $this->tokens->put(new OneTimeToken(SequentialIdGenerator::id(950), $this->user->id, TokenPurpose::VerifyEmail, hash('sha256', 'new-link'), $this->now->modify('+24 hours'), null));

                return parent::verify($password, $hash);
            }
        };

        self::assertInstanceOf(InvalidCredentials::class, $this->failure(fn () => $this->confirm('old-link', 'not the password')));

        $tokens = $this->oneTimeTokens->all();
        self::assertCount(1, $tokens);
        self::assertSame(hash('sha256', 'new-link'), $tokens[0]->tokenHash);
        self::assertSame(0, $tokens[0]->failedAttempts);
    }

    public function test_the_account_is_locked_before_the_token_is(): void
    {
        // The one order every use case takes its locks in. A second registration for the same
        // address holds the account and then replaces the token: taken the other way round here,
        // the two would wait for each other.
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->transactions->locks = [];

        $this->confirm('token-1');

        // Twice: to count the try, and to confirm.
        self::assertSame(['users', 'one_time_tokens', 'users', 'one_time_tokens'], $this->transactions->locks);
    }

    public function test_the_account_is_locked_before_the_token_is_when_a_worn_out_link_is_deleted(): void
    {
        $user = $this->givenUser(verified: false);
        $this->givenToken($user, 'link', failedAttempts: OneTimeTokens::MAX_FAILED_ATTEMPTS - 1);

        $this->failure(fn () => $this->confirm('link', 'not the password'));

        self::assertSame(['users', 'one_time_tokens', 'users', 'one_time_tokens'], $this->transactions->locks);
        self::assertSame([], $this->oneTimeTokens->all());
    }

    public function test_a_wrong_password_on_a_link_with_tries_left_needs_no_second_transaction(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->transactions->locks = [];
        $commits = $this->transactions->commits;

        $this->failure(fn () => $this->confirm('token-1', 'not the password'));

        self::assertSame(['users', 'one_time_tokens'], $this->transactions->locks);
        self::assertSame(1, $this->transactions->commits - $commits);
        self::assertSame(0, $this->transactions->rollbacks);
    }

    private function forgetWhatWasCounted(): void
    {
        $this->counters = new InMemoryRateLimitCounters($this->transactions);
    }

    private function givenToken(
        User $user,
        string $token,
        TokenPurpose $purpose = TokenPurpose::VerifyEmail,
        ?DateTimeImmutable $expiresAt = null,
        ?DateTimeImmutable $usedAt = null,
        int $failedAttempts = 0,
    ): void {
        $this->oneTimeTokens->put(new OneTimeToken(
            $this->ids->next(),
            $user->id,
            $purpose,
            hash('sha256', $token),
            $expiresAt ?? $this->now()->modify('+1 hour'),
            $usedAt,
            $failedAttempts,
        ));
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
