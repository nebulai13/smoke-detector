<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Privacy;

use ArrayObject;
use Closure;
use RuntimeException;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\AuthLimits;
use SmokeDetector\Application\Privacy\DeleteAccount;
use SmokeDetector\Application\Privacy\PrivacyLimits;
use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Application\Privacy\UserDataTable;
use SmokeDetector\Application\Privacy\WrongPassword;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Tests\Support\Fake\FakePasswordHasher;
use SmokeDetector\Tests\Support\Fake\RecordingUserData;
use SmokeDetector\Tests\Support\InMemory\InMemoryUserRepository;
use SmokeDetector\Tests\Support\RateLimitKey;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\AccountUseCaseTestCase;
use Throwable;

/**
 * DELETE /me (HLD §4.5 "Account", FR-ACC-08): after the password was entered again, everything
 * about the user is erased in one transaction, and then the account itself.
 */
final class DeleteAccountTest extends AccountUseCaseTestCase
{
    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = new ArrayObject();
    }

    public function test_FR_ACC_08_with_the_right_password_every_eraser_runs_and_then_the_account_is_gone(): void
    {
        $user = $this->givenUser();
        $existedWhileErasing = [];
        $whileErasing = function (UserId $userId) use (&$existedWhileErasing): void {
            $existedWhileErasing[] = $this->users->find($userId) !== null;
        };

        $this->deleteAccount([
            $this->table('refresh_tokens', 'sessions', $whileErasing),
            $this->table('plans', 'plan', $whileErasing),
        ])->execute($user->id, self::PASSWORD, self::CLIENT);

        self::assertSame(
            ["erase refresh_tokens {$user->id->value}", "erase plans {$user->id->value}"],
            $this->log->getArrayCopy(),
        );
        // The account goes last: what refers to it is erased while it still exists.
        self::assertSame([true, true], $existedWhileErasing);
        self::assertNull($this->users->find($user->id));
        self::assertNull($this->users->findByEmail(self::email()));
    }

    public function test_FR_ACC_08_everything_is_erased_in_one_transaction(): void
    {
        $user = $this->givenUser();
        $active = [];
        $probe = function () use (&$active): void {
            $active[] = $this->transactions->active;
        };

        $this->deleteAccount([$this->table('plans', 'plan', $probe), $this->table('consents', 'consents', $probe)])
            ->execute($user->id, self::PASSWORD, self::CLIENT);

        self::assertSame([true, true], $active);
        self::assertSame([1, 0], [$this->transactions->commits, $this->transactions->rollbacks]);
    }

    public function test_FR_ACC_08_when_one_eraser_fails_nothing_is_deleted_at_all(): void
    {
        $user = $this->givenUser();
        $this->givenPlan($user);
        $delete = $this->deleteAccount([
            // A real change of the first table, which the failure of the second must undo.
            $this->table('plans', 'plan', fn (UserId $userId) => $this->plans->eraseOfUser($userId)),
            $this->table('consents', 'consents', static fn () => throw new RuntimeException('The database went away.')),
            $this->table('refresh_tokens', 'sessions'),
        ]);

        $failure = self::failure(static fn () => $delete->execute($user->id, self::PASSWORD, self::CLIENT));

        self::assertSame('The database went away.', $failure->getMessage());
        self::assertEquals($user, $this->users->find($user->id));
        self::assertNotNull($this->plans->find($user->id));
        self::assertSame(["erase plans {$user->id->value}", "erase consents {$user->id->value}"], $this->log->getArrayCopy());
        self::assertSame([0, 1], [$this->transactions->commits, $this->transactions->rollbacks]);
    }

    public function test_FR_ACC_08_a_wrong_password_deletes_nothing(): void
    {
        $user = $this->givenUser();
        $delete = $this->deleteAccount([$this->table('plans', 'plan')]);

        foreach (['wrong password', '', self::PASSWORD . ' ', ' ' . self::PASSWORD, strtoupper(self::PASSWORD)] as $password) {
            $failure = self::failure(static fn () => $delete->execute($user->id, $password, self::CLIENT));

            self::assertInstanceOf(WrongPassword::class, $failure);
            self::assertSame('The password is wrong.', $failure->getMessage());
        }
        self::assertEquals($user, $this->users->find($user->id));
        self::assertCount(0, $this->log);
        self::assertSame(0, $this->transactions->commits + $this->transactions->rollbacks);
    }

    public function test_FR_ACC_08_the_password_is_checked_against_the_hash_of_this_account_exactly_as_typed(): void
    {
        $user = $this->givenUser(password: ' spaces around ');
        $this->givenUser('bob@example.org', 'bobs password');

        $this->deleteAccount([])->execute($user->id, ' spaces around ', self::CLIENT);

        self::assertSame([['password' => ' spaces around ', 'hash' => 'fake-v1: spaces around ']], $this->hasher->verified);
    }

    public function test_FR_ACC_08_the_password_of_somebody_else_does_not_delete_an_account(): void
    {
        $anna = $this->givenUser('anna@example.org', 'annas password');
        $bob = $this->givenUser('bob@example.org', 'bobs password');

        $failure = self::failure(fn () => $this->deleteAccount([])->execute($anna->id, 'bobs password', self::CLIENT));

        self::assertInstanceOf(WrongPassword::class, $failure);
        self::assertCount(2, $this->users->all());
        self::assertNotNull($this->users->find($bob->id));
    }

    public function test_FR_ACC_08_only_the_account_of_the_user_is_deleted(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $bob = $this->givenUser('bob@example.org');

        $this->deleteAccount([$this->table('plans', 'plan')])->execute($anna->id, self::PASSWORD, self::CLIENT);

        self::assertEquals([$bob], $this->users->all());
        self::assertSame(["erase plans {$anna->id->value}"], $this->log->getArrayCopy());
    }

    public function test_NFR_05_a_wrong_password_counts_as_a_failed_login_under_the_same_three_rules(): void
    {
        $user = $this->givenUser();

        self::failure(fn () => $this->deleteAccount([])->execute($user->id, 'wrong', self::CLIENT));

        // With the account's email address and the client the request came from.
        self::assertSame(['client' => 1, 'pair' => 1, 'address' => 1], $this->failedLoginsCounted('anna@example.org', self::CLIENT));
        self::assertCount(3, $this->counters->all());
    }

    public function test_NFR_05_after_five_wrong_passwords_from_a_client_even_the_right_one_is_refused_until_the_window_ends(): void
    {
        $user = $this->givenUser();
        $delete = $this->deleteAccount([$this->table('plans', 'plan')]);
        for ($i = 1; $i <= 5; $i++) {
            self::assertInstanceOf(WrongPassword::class, self::failure(static fn () => $delete->execute($user->id, "guess {$i}", self::CLIENT)));
        }

        $refused = self::failure(static fn () => $delete->execute($user->id, self::PASSWORD, self::CLIENT));

        self::assertInstanceOf(RateLimitExceeded::class, $refused);
        self::assertSame(900, $refused->retryAfterSeconds);
        self::assertNotNull($this->users->find($user->id));
        // The password was not even looked at.
        self::assertCount(5, $this->hasher->verified);

        $this->clock->advanceSeconds(900);
        $delete->execute($user->id, self::PASSWORD, self::CLIENT);
        self::assertNull($this->users->find($user->id));
    }

    public function test_NFR_05_a_stranger_who_hammers_the_address_cannot_keep_the_owner_from_deleting_the_account(): void
    {
        // SRS OQ-11: five wrong logins used to lock the address, and with it DELETE /me.
        $user = $this->givenUser();
        for ($i = 1; $i <= 8; $i++) {
            self::failure(fn () => $this->logIn()->execute(self::email(), "guess {$i}", null, '198.51.100.66'));
        }

        $this->deleteAccount([])->execute($user->id, self::PASSWORD, self::CLIENT);

        self::assertNull($this->users->find($user->id));
    }

    public function test_NFR_05_guesses_at_login_and_at_deletion_share_one_budget(): void
    {
        // It is the same password that is being guessed, so it is the same five attempts.
        $user = $this->givenUser();
        for ($i = 1; $i <= 3; $i++) {
            self::failure(fn () => $this->logIn()->execute(self::email(), "guess {$i}", null, self::CLIENT));
        }
        for ($i = 4; $i <= 5; $i++) {
            self::failure(fn () => $this->deleteAccount([])->execute($user->id, "guess {$i}", self::CLIENT));
        }

        self::assertInstanceOf(RateLimitExceeded::class, self::failure(fn () => $this->deleteAccount([])->execute($user->id, self::PASSWORD, self::CLIENT)));
        self::assertInstanceOf(RateLimitExceeded::class, self::failure(fn () => $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT)));
        self::assertNotNull($this->users->find($user->id));
    }

    public function test_NFR_05_the_51st_wrong_password_for_the_address_within_an_hour_is_refused_for_everybody(): void
    {
        $user = $this->givenUser();
        for ($i = 1; $i <= 50; $i++) {
            self::failure(fn () => $this->logIn()->execute(self::email(), 'a guess', null, "198.51.100.{$i}"));
        }

        $refused = self::failure(fn () => $this->deleteAccount([])->execute($user->id, self::PASSWORD, self::CLIENT));

        self::assertInstanceOf(RateLimitExceeded::class, $refused);
        self::assertSame(3600, $refused->retryAfterSeconds);
        self::assertNotNull($this->users->find($user->id));
    }

    public function test_NFR_05_a_client_over_its_limit_is_refused_before_it_can_use_up_the_accounts_attempts(): void
    {
        $user = $this->givenUser();
        // Thirty wrong passwords from one client, five each for six other accounts.
        for ($account = 1; $account <= 6; $account++) {
            $other = $this->givenUser("other{$account}@example.org");
            for ($i = 1; $i <= 5; $i++) {
                self::assertInstanceOf(WrongPassword::class, self::failure(fn () => $this->deleteAccount([])->execute($other->id, "guess {$i}", self::CLIENT)));
            }
        }

        $refused = self::failure(fn () => $this->deleteAccount([])->execute($user->id, 'wrong', self::CLIENT));

        self::assertInstanceOf(RateLimitExceeded::class, $refused);
        self::assertSame(['client' => 31, 'pair' => 0, 'address' => 0], $this->failedLoginsCounted('anna@example.org', self::CLIENT));
    }

    public function test_FR_ACC_08_a_deleted_account_stays_deleted_when_the_counted_attempt_cannot_be_taken_back_and_its_counters_still_go(): void
    {
        $user = $this->givenUser();
        $this->failedLogins()->count(self::email(), '198.51.100.1', $this->now());
        (new RateLimiter($this->counters, RateLimitKey::hasher()))->consume(PrivacyLimits::exportsPerAccount(), $user->id->value, $this->now());
        $this->counters->failDecrement = new RuntimeException('The database went away while counting for anna@example.org.');

        // No failure reaches the caller: the account is gone, and "try again" would find nothing.
        $this->deleteAccount([])->execute($user->id, self::PASSWORD, self::CLIENT);

        self::assertNull($this->users->find($user->id));
        // What was counted about the person is deleted although the step before failed.
        $left = implode(' ', array_keys($this->counters->all()));
        self::assertStringNotContainsString(RateLimitKey::subjectHash('anna@example.org'), $left);
        self::assertStringNotContainsString(RateLimitKey::subjectHash($user->id->value), $left);
        self::assertSame(
            [['level' => 'warning', 'message' => 'A rate limit counter could not be updated after the work was done.', 'context' => ['exception' => RuntimeException::class]]],
            $this->logger->records,
        );
        self::assertStringNotContainsString('anna', $this->logger->text());
    }

    public function test_FR_ACC_08_when_the_counters_of_the_address_cannot_be_deleted_those_of_the_user_id_still_are(): void
    {
        $user = $this->givenUser();
        $this->failedLogins()->count(self::email(), '198.51.100.1', $this->now());
        (new RateLimiter($this->counters, RateLimitKey::hasher()))->consume(PrivacyLimits::exportsPerAccount(), $user->id->value, $this->now());
        // The first of the two "forget" steps fails.
        $this->counters->failNextSuffixDeletes = 1;

        $this->deleteAccount([])->execute($user->id, self::PASSWORD, self::CLIENT);

        self::assertNull($this->users->find($user->id));
        $left = implode(' ', array_keys($this->counters->all()));
        // Left to the cleanup, which deletes every counter after two days.
        self::assertStringContainsString(RateLimitKey::subjectHash('anna@example.org'), $left);
        self::assertStringNotContainsString(RateLimitKey::subjectHash($user->id->value), $left);
        self::assertCount(1, $this->logger->records);
    }

    public function test_FR_ACC_08_nothing_that_was_counted_about_the_user_stays_behind(): void
    {
        $user = $this->givenUser();
        $limiter = new RateLimiter($this->counters, RateLimitKey::hasher());
        // Earlier failures and requests of this user, keyed by the email address (alone, or
        // together with a client address) and by the user id.
        $this->failedLogins()->count(self::email(), '198.51.100.1', $this->now());
        $this->failedLogins()->count(self::email(), '198.51.100.2', $this->now()->modify('-1 hour'));
        $this->mailBudget()->spend(self::email(), '198.51.100.3', $this->now()->modify('-1 day'));
        $limiter->consume(AuthLimits::confirmationMailsByLoginPerEmail(), 'anna@example.org', $this->now());
        $limiter->consume(PrivacyLimits::exportsPerAccount(), $user->id->value, $this->now());
        // Of somebody else, and of the client addresses, which are not the user's alone.
        $this->failedLogins()->count(self::email('bob@example.org'), self::CLIENT, $this->now());

        $this->deleteAccount([])->execute($user->id, self::PASSWORD, self::CLIENT);

        $left = array_keys($this->counters->all());
        sort($left);
        $expected = [
            RateLimitKey::of('login.address', 'bob@example.org'),
            RateLimitKey::of('login.client', '198.51.100.1'),
            RateLimitKey::of('login.client', '198.51.100.2'),
            RateLimitKey::of('login.client', self::CLIENT),
            RateLimitKey::of('login.pair', 'bob@example.org', self::CLIENT),
            RateLimitKey::of('mail.client', '198.51.100.3'),
        ];
        sort($expected);
        self::assertSame($expected, $left);
        // The successful attempt itself was taken back, the client's earlier failure was not.
        self::assertSame(1, $this->hits(AuthLimits::loginFailuresPerClient(), self::CLIENT));
    }

    public function test_FR_ACC_08_a_password_that_was_changed_while_it_was_being_checked_deletes_nothing(): void
    {
        $user = $this->givenUser();
        $this->hasher = new class ($this->users, $user) extends FakePasswordHasher {
            public function __construct(private readonly InMemoryUserRepository $users, private readonly User $user)
            {
            }

            public function verify(string $password, string $hash): bool
            {
                $correct = parent::verify($password, $hash);
                // A password reset on another device, at this very moment.
                $this->users->update($this->user->withPasswordHash('fake-v1:a new password'));

                return $correct;
            }
        };

        $failure = self::failure(fn () => $this->deleteAccount([$this->table('plans', 'plan')])->execute($user->id, self::PASSWORD, self::CLIENT));

        self::assertInstanceOf(WrongPassword::class, $failure);
        self::assertNotNull($this->users->find($user->id));
        self::assertCount(0, $this->log);
        // The old password is a failed attempt now.
        self::assertSame(['client' => 1, 'pair' => 1, 'address' => 1], $this->failedLoginsCounted('anna@example.org', self::CLIENT));
    }

    public function test_FR_ACC_08_an_account_that_was_deleted_while_the_password_was_being_checked_is_reported_as_gone(): void
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

        $failure = self::failure(fn () => $this->deleteAccount([$this->table('plans', 'plan')])->execute($user->id, self::PASSWORD, self::CLIENT));

        self::assertInstanceOf(AccountNotFound::class, $failure);
        self::assertCount(0, $this->log);
    }

    public function test_FR_ACC_08_an_account_that_does_not_exist_is_reported_as_gone_and_nothing_is_counted(): void
    {
        $this->givenUser();

        $failure = self::failure(fn () => $this->deleteAccount([$this->table('plans', 'plan')])->execute(TestData::userId(999), self::PASSWORD, self::CLIENT));

        self::assertInstanceOf(AccountNotFound::class, $failure);
        $this->assertNothingCounted();
        self::assertCount(1, $this->users->all());
        self::assertSame([], $this->hasher->verified);
    }

    /**
     * @param list<UserDataTable> $tables
     */
    private function deleteAccount(array $tables): DeleteAccount
    {
        return new DeleteAccount(
            $this->transactions,
            $this->clock,
            $this->failedLogins(),
            new RateLimiter($this->counters, RateLimitKey::hasher()),
            $this->hasher,
            $this->users,
            new UserDataRegistry($tables),
            $this->logger,
        );
    }

    /**
     * @param (Closure(UserId): void)|null $onErase
     */
    private function table(string $table, string $member, ?Closure $onErase = null): UserDataTable
    {
        $data = new RecordingUserData($table, $this->log, [], $onErase);

        return new UserDataTable($table, $member, $data, $data);
    }

    /**
     * @param callable(): mixed $attempt
     */
    private static function failure(callable $attempt): Throwable
    {
        try {
            $attempt();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('Expected the attempt to fail.');
    }
}
