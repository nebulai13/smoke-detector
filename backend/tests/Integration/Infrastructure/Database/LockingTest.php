<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Database;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Application\Account\Consent;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Auth\InvalidCredentials;
use SmokeDetector\Application\Auth\InvalidToken;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\TemporarilyUnavailable;
use SmokeDetector\Infrastructure\Auth\HmacRefreshSecrets;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\PdoTransactionRunner;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoConsentRepository;
use SmokeDetector\Infrastructure\Repository\PdoOneTimeTokenRepository;
use SmokeDetector\Infrastructure\Repository\PdoRefreshTokenRepository;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;

/**
 * What two requests at the same moment do to each other in the database (MariaDB, REPEATABLE READ).
 *
 * - Requests of different users must not wait for each other at all.
 * - Requests about the same account wait for it in one order, the account's row first, so that
 *   they queue up instead of blocking each other for good (a deadlock, error 1213).
 * - Should the database still give a request up (deadlock, or a lock that does not come free in
 *   time), that is reported as TemporarilyUnavailable, which the API answers as 503 with
 *   Retry-After, and not as a bug (500).
 */
final class LockingTest extends IntegrationTestCase
{
    /** Stands for the refresh token of the test session in the data provider, which cannot ask the settings. */
    private const string REFRESH_TOKEN = '<the refresh token>';

    private const string NOW = '2026-11-17 12:00:00';

    /** @var list<resource> */
    private array $processes = [];

    /** @var array<int, array<int, resource>> By process. */
    private array $pipes = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        $this->processes = [];
    }

    public function test_a_lock_that_does_not_come_free_in_time_is_reported_as_temporarily_unavailable(): void
    {
        [$holder, $waiting] = [$this->session(), $this->session()];
        $this->sql()->execute("INSERT INTO rate_limits (`key`, window_start, hits) VALUES ('k', '2026-11-17 12:00:00', 1)");
        $holder->pdo()->beginTransaction();
        (new Sql($holder))->execute("UPDATE rate_limits SET hits = 2 WHERE `key` = 'k'");

        try {
            (new Sql($waiting))->execute("UPDATE rate_limits SET hits = 3 WHERE `key` = 'k'");
            self::fail('Expected the second session to give up waiting.');
        } catch (TemporarilyUnavailable $busy) {
            self::assertSame(1, $busy->retryAfterSeconds);
            // The message is fixed. What the database said is in the cause, for the log.
            self::assertSame('The database could not finish the work right now.', $busy->getMessage());
            self::assertInstanceOf(PDOException::class, $busy->getPrevious());
            self::assertSame(1205, $busy->getPrevious()->errorInfo[1] ?? null);
        } finally {
            $holder->pdo()->rollBack();
        }
        self::assertSame(1, $this->sql()->value("SELECT hits FROM rate_limits WHERE `key` = 'k'"));
    }

    public function test_a_deadlock_is_reported_as_temporarily_unavailable_and_any_other_error_stays_what_it_is(): void
    {
        // The database says "deadlock" with error 1213 to the one request it gives up. Raised here by
        // hand, because which of two real requests is given up is the database's choice.
        $sql = new Sql($this->session());

        try {
            $sql->execute("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Deadlock found when trying to get lock'");
            self::fail('Expected the statement to fail.');
        } catch (TemporarilyUnavailable $busy) {
            self::assertSame(1213, $busy->getPrevious() instanceof PDOException ? $busy->getPrevious()->errorInfo[1] ?? null : null);
        }

        // The same SQLSTATE class with another error number, and an ordinary mistake: not "try again".
        foreach (["SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1614, MESSAGE_TEXT = 'XA_RBDEADLOCK'", 'SELECT * FROM no_such_table'] as $statement) {
            try {
                $sql->execute($statement);
                self::fail('Expected the statement to fail.');
            } catch (PDOException) {
            }
        }
    }

    public function test_the_transaction_runner_rolls_back_and_passes_temporarily_unavailable_on(): void
    {
        $database = $this->session();
        $transactions = new PdoTransactionRunner($database);

        try {
            $transactions->run(static function () use ($database): void {
                $sql = new Sql($database);
                $sql->execute("INSERT INTO rate_limits (`key`, window_start, hits) VALUES ('before', '2026-11-17 12:00:00', 1)");
                $sql->execute("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Deadlock found when trying to get lock'");
            });
            self::fail('Expected the work to fail.');
        } catch (TemporarilyUnavailable) {
        }

        self::assertFalse($database->pdo()->inTransaction());
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM rate_limits'));
    }

    public function test_two_new_registrations_at_the_same_moment_do_not_wait_for_each_other(): void
    {
        // What RegisterUser stores for a new address: the account, its consents, its confirmation
        // token. Two sessions do it for two addresses, each inside its open transaction. Neither
        // may have to wait for the other (the second would give up after a second here).
        //
        // (The accounts have ids that no other test uses. A row that was deleted a moment ago is
        // still in the index for a while, and an insert of the same key then also looks at the row
        // after it: with ids that tests share, this test would wait for the leftovers of another.)
        [$first, $second] = [$this->session(), $this->session()];
        $first->pdo()->beginTransaction();
        $second->pdo()->beginTransaction();

        $this->registerIn($first, 7101);
        $this->registerIn($second, 7102);
        // And again, as when both ask for their link once more: now there is a token to replace.
        (new PdoOneTimeTokenRepository(new Sql($first)))->put(TestData::oneTimeToken(7131, TestData::userId(7101), 'again-1'));
        (new PdoOneTimeTokenRepository(new Sql($second)))->put(TestData::oneTimeToken(7132, TestData::userId(7102), 'again-2'));

        $first->pdo()->commit();
        $second->pdo()->commit();
        self::assertSame(2, $this->sql()->value('SELECT COUNT(*) FROM users'));
        self::assertSame(6, $this->sql()->value('SELECT COUNT(*) FROM consents'));
        self::assertSame(
            [hash('sha256', 'again-1'), hash('sha256', 'again-2')],
            $this->sql()->column('SELECT token_hash FROM one_time_tokens ORDER BY user_id'),
        );
    }

    public function test_two_reset_requests_of_different_users_at_the_same_moment_do_not_wait_for_each_other(): void
    {
        // The usual case: neither user has a token yet, so there is nothing to replace. Looking for
        // "the old token" with a DELETE would lock the empty place both new rows go into.
        (new PdoUserRepository($this->sql()))->add(TestData::user(7201));
        (new PdoUserRepository($this->sql()))->add(TestData::user(7202));
        [$first, $second] = [$this->session(), $this->session()];
        $first->pdo()->beginTransaction();
        $second->pdo()->beginTransaction();

        foreach ([[$first, 7201], [$second, 7202], [$first, 7201], [$second, 7202]] as $round => [$session, $user]) {
            (new PdoUserRepository(new Sql($session)))->lock(TestData::userId($user));
            (new PdoOneTimeTokenRepository(new Sql($session)))->put(
                TestData::oneTimeToken(7240 + $round, TestData::userId($user), "reset-{$round}", TokenPurpose::ResetPassword),
            );
        }

        $first->pdo()->commit();
        $second->pdo()->commit();
        self::assertSame(
            [hash('sha256', 'reset-2'), hash('sha256', 'reset-3')],
            $this->sql()->column('SELECT token_hash FROM one_time_tokens ORDER BY user_id'),
        );
    }

    public function test_deleting_before_inserting_is_what_made_two_registrations_wait_for_each_other(): void
    {
        // The control for the two tests above: replaceAll() deletes first, also when there is nothing
        // to delete, and the second session's insert then waits for the first session to end. That
        // is why a new account's consents are stored with add().
        (new PdoUserRepository($this->sql()))->add(TestData::user(7301));
        (new PdoUserRepository($this->sql()))->add(TestData::user(7302));
        [$first, $second] = [$this->session(), $this->session()];
        $first->pdo()->beginTransaction();
        $second->pdo()->beginTransaction();
        $consents = [new Consent(ConsentType::Age18, 'v1', TestData::time(self::NOW))];

        (new PdoConsentRepository(new Sql($first)))->replaceAll(TestData::userId(7301), $consents);
        try {
            (new PdoConsentRepository(new Sql($second)))->replaceAll(TestData::userId(7302), $consents);
            $waited = false;
        } catch (TemporarilyUnavailable) {
            $waited = true;
        } finally {
            $first->pdo()->rollBack();
            $second->pdo()->rollBack();
        }

        self::assertTrue($waited, 'replaceAll() for two new accounts no longer blocks: the reason for add() is gone.');
    }

    /**
     * @return iterable<string, array{string, string, string, string}> The action of the second
     *                                                                 process, its token, the table
     *                                                                 and the row that must be free.
     */
    public static function useCasesThatNeedTheAccountAndSomethingElse(): iterable
    {
        yield 'verify-email (while the address is registered again)' => ['verify-email', 'verify-token', 'one_time_tokens', TestData::uuid(10)];
        yield 'password reset (while a new reset link is requested)' => ['reset-password', 'reset-token', 'one_time_tokens', TestData::uuid(11)];
        yield 'refresh (while the account is deleted)' => ['refresh', self::REFRESH_TOKEN, 'refresh_tokens', TestData::uuid(20)];
        yield 'logout (while the account is deleted)' => ['logout', self::REFRESH_TOKEN, 'refresh_tokens', TestData::uuid(20)];
    }

    #[DataProvider('useCasesThatNeedTheAccountAndSomethingElse')]
    public function test_a_use_case_waits_for_the_account_before_it_locks_anything_else(string $action, string $token, string $table, string $rowId): void
    {
        // Registration, a reset request and account deletion lock the account and then write its
        // tokens and sessions. A use case that starts from a token must therefore not hold the token
        // while it waits for the account, or the two wait for each other for good.
        $this->givenAnAccountWithTokens($action === 'verify-email');
        $holder = $this->session();
        $holder->pdo()->beginTransaction();
        (new PdoUserRepository(new Sql($holder)))->lock(TestData::userId(1));

        $process = $this->start($action, $token === self::REFRESH_TOKEN ? $this->refreshToken() : $token);
        $this->waitUntilAnotherSessionWaitsForTheAccount();

        // The one that holds the account can take the other row at once: nobody else has it.
        self::assertSame($rowId, (new Sql($holder))->value("SELECT id FROM {$table} WHERE id = ? FOR UPDATE NOWAIT", [$rowId]));
        $holder->pdo()->commit();
        self::assertSame('ok', $this->outputOf($process));
    }

    public function test_FR_ACC_04_a_refresh_that_waited_for_another_refresh_of_the_same_token_is_answered_and_rotates_nothing(): void
    {
        // SRS OQ-12: two browser tabs refresh with the same token at the same moment. The second
        // waits for the account while the first replaces the token. It must then see the session as
        // the first left it and be answered like a repeated refresh. MariaDB (11.8,
        // innodb_snapshot_isolation) gives a transaction up with error 1020 "Record has changed
        // since last read" when it locks a row that was changed after its own first plain read:
        // the read that only tells which account to lock must therefore not be part of the transaction.
        $this->givenAnAccountWithTokens(false);
        $holder = $this->session();
        $holder->pdo()->beginTransaction();
        (new PdoUserRepository(new Sql($holder)))->lock(TestData::userId(1));

        $process = $this->start('refresh', $this->refreshToken());
        $this->waitUntilAnotherSessionWaitsForTheAccount();

        // What the first refresh does before it commits: the successor takes the token's place.
        $successor = 'key.' . (new HmacRefreshSecrets($this->settings()->jwtSecret))->secret('key', 1);
        (new PdoRefreshTokenRepository(new Sql($holder)))->rotate(
            TestData::uuid(20),
            hash('sha256', $successor),
            1,
            TestData::time(self::NOW),
            TestData::time('2027-01-16 12:00:00'),
        );
        $holder->pdo()->commit();

        self::assertSame('ok', $this->outputOf($process));
        // Answered from what the first refresh stored: not rotated again, and not ended.
        self::assertSame(
            [['token_hash' => hash('sha256', $successor), 'generation' => 1, 'rotated_at' => self::NOW, 'revoked_at' => null]],
            $this->sql()->rows('SELECT token_hash, generation, rotated_at, revoked_at FROM refresh_tokens'),
        );
    }

    public function test_FR_ACC_04_a_logout_that_waited_for_a_refresh_of_the_same_session_still_ends_it(): void
    {
        // The user logs out in one tab while another tab refreshes. The logout waits for the
        // account, the refresh replaces the token. The logout must then end the session, and not be
        // given up by the database with error 1020 (see the test above), which would leave the
        // session open with the token the refresh has just handed out.
        $this->givenAnAccountWithTokens(false);
        $holder = $this->session();
        $holder->pdo()->beginTransaction();
        (new PdoUserRepository(new Sql($holder)))->lock(TestData::userId(1));

        $process = $this->start('logout', $this->refreshToken());
        $this->waitUntilAnotherSessionWaitsForTheAccount();

        $successor = 'key.' . (new HmacRefreshSecrets($this->settings()->jwtSecret))->secret('key', 1);
        (new PdoRefreshTokenRepository(new Sql($holder)))->rotate(
            TestData::uuid(20),
            hash('sha256', $successor),
            1,
            TestData::time(self::NOW),
            TestData::time('2027-01-16 12:00:00'),
        );
        $holder->pdo()->commit();

        self::assertSame('ok', $this->outputOf($process));
        self::assertSame(
            [['token_hash' => hash('sha256', $successor), 'generation' => 1, 'revoked_at' => self::NOW]],
            $this->sql()->rows('SELECT token_hash, generation, revoked_at FROM refresh_tokens'),
        );
    }

    public function test_FR_ACC_03_a_resend_that_waited_for_another_request_about_the_same_registration_is_answered(): void
    {
        // MariaDB 11.8 gives a transaction up (error 1020, "Record has changed since last read") when it
        // locks a row that was changed after the transaction's first plain read. A use case that looked
        // the account up inside its transaction answered 500 here, and only for a pending registration:
        // the status code told who is registered.
        $this->givenAnAccountWithTokens(true);
        $email = $this->emailOfTheAccount();
        $holder = $this->session();
        $holder->pdo()->beginTransaction();
        (new PdoUserRepository(new Sql($holder)))->lock(TestData::userId(1));

        $process = $this->start('resend-verification', $email);
        $this->waitUntilAnotherSessionWaitsForTheAccount();
        // What a resend that came a moment earlier does: the 24 hours start again, a new token.
        (new Sql($holder))->execute("UPDATE users SET created_at = '2026-11-17 12:00:01' WHERE id = ?", [TestData::userId(1)->value]);
        (new PdoOneTimeTokenRepository(new Sql($holder)))->put(TestData::oneTimeToken(10, TestData::userId(1), 'verify-token-2', expiresAt: '2026-11-18 12:00:00'));
        $holder->pdo()->commit();

        self::assertSame('ok', $this->outputOf($process));
    }

    public function test_FR_ACC_05_a_reset_request_that_waited_for_another_request_about_the_same_account_is_answered(): void
    {
        $this->givenAnAccountWithTokens(false);
        $email = $this->emailOfTheAccount();
        $holder = $this->session();
        $holder->pdo()->beginTransaction();
        (new PdoUserRepository(new Sql($holder)))->lock(TestData::userId(1));

        $process = $this->start('request-password-reset', $email);
        $this->waitUntilAnotherSessionWaitsForTheAccount();
        // What a reset request that came a moment earlier does: a new token.
        (new PdoOneTimeTokenRepository(new Sql($holder)))->put(TestData::oneTimeToken(11, TestData::userId(1), 'reset-token-2', TokenPurpose::ResetPassword, '2026-11-17 13:00:00'));
        $holder->pdo()->commit();

        self::assertSame('ok', $this->outputOf($process));
    }

    public function test_FR_ACC_01_a_registration_that_waited_for_another_request_about_the_same_address_is_answered(): void
    {
        $this->givenAnAccountWithTokens(true);
        $email = $this->emailOfTheAccount();
        $holder = $this->session();
        $holder->pdo()->beginTransaction();
        (new PdoUserRepository(new Sql($holder)))->lock(TestData::userId(1));

        $process = $this->start('register', $email);
        $this->waitUntilAnotherSessionWaitsForTheAccount();
        // What a registration of the same address that came a moment earlier does.
        (new Sql($holder))->execute("UPDATE users SET display_name = 'Somebody' WHERE id = ?", [TestData::userId(1)->value]);
        $holder->pdo()->commit();

        self::assertSame('ok', $this->outputOf($process));
    }

    public function test_FR_ACC_05_a_reset_that_waited_for_another_reset_with_the_same_link_is_refused_as_used(): void
    {
        $this->givenAnAccountWithTokens(false);
        $holder = $this->session();
        $holder->pdo()->beginTransaction();
        (new PdoUserRepository(new Sql($holder)))->lock(TestData::userId(1));

        $process = $this->start('reset-password', 'reset-token');
        $this->waitUntilAnotherSessionWaitsForTheAccount();
        // Another confirm of the same link came first: it used the token and changed the password.
        (new Sql($holder))->execute("UPDATE one_time_tokens SET used_at = '2026-11-17 12:00:00' WHERE id = ?", [TestData::uuid(11)]);
        (new Sql($holder))->execute('UPDATE users SET token_version = token_version + 1 WHERE id = ?', [TestData::userId(1)->value]);
        $holder->pdo()->commit();

        self::assertSame(InvalidToken::class, $this->outputOf($process));
    }

    #[DataProvider('requestsAboutOneAddress')]
    public function test_FR_ACC_01_three_requests_about_one_address_at_the_same_moment_are_all_answered(string $action, ?bool $unconfirmed): void
    {
        // The real thing, not staged: three processes run the use case at once. Before the lookups
        // moved in front of the transactions, which of them failed depended on whether the address
        // was unknown, waiting for its confirmation or confirmed, and so told who is registered.
        $email = 'user1@example.org';
        if ($unconfirmed !== null) {
            $this->givenAnAccountWithTokens($unconfirmed);
            $email = $this->emailOfTheAccount();
        }

        $processes = [$this->start($action, $email), $this->start($action, $email), $this->start($action, $email)];

        self::assertSame(['ok', 'ok', 'ok'], array_map($this->outputOf(...), $processes));
        // And they left one account at most, with one confirmation or reset link at most per purpose.
        self::assertLessThanOrEqual(1, $this->sql()->value('SELECT COUNT(*) FROM users'));
        self::assertLessThanOrEqual(1, $this->sql()->value('SELECT MAX(links) FROM (SELECT COUNT(*) AS links FROM one_time_tokens GROUP BY purpose) AS per_purpose') ?? 0);
    }

    public function test_FR_ACC_04_three_logins_to_one_account_at_the_same_moment_all_get_a_session(): void
    {
        // Three devices of one user logging in at once, as three real processes. Login is the one
        // use case that reads the sessions of the account inside its transaction.
        $this->givenAnAccountWithTokens(false);
        $email = $this->emailOfTheAccount();

        $processes = [$this->start('login', $email), $this->start('login', $email), $this->start('login', $email)];

        self::assertSame(['ok', 'ok', 'ok'], array_map($this->outputOf(...), $processes));
        // The session the account had, and three new ones.
        self::assertSame(4, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens'));
        // Every attempt was counted before its password was checked and taken back afterwards:
        // nothing is held against the client or the address.
        self::assertSame([], $this->sql()->column('SELECT hits FROM rate_limits WHERE hits <> 0'));
    }

    public function test_FR_ACC_08_logins_that_race_the_deletion_of_the_account_get_a_session_or_invalid_credentials_and_never_an_error(): void
    {
        $this->givenAnAccountWithTokens(false);
        $email = $this->emailOfTheAccount();

        $processes = [
            $this->start('login', $email),
            $this->start('delete-account', TestData::userId(1)->value),
            $this->start('login', $email),
            $this->start('login', $email),
        ];
        $outputs = array_map($this->outputOf(...), $processes);

        // The owner's deletion goes through whatever the logins do.
        self::assertSame('ok', $outputs[1]);
        // A login that came first got its session (which then went with the account); one that
        // came later is told what every unknown address is told. Nothing else: no error class.
        foreach ([0, 2, 3] as $login) {
            self::assertContains($outputs[$login], ['ok', InvalidCredentials::class]);
        }
        // Whatever the order was, nothing of the account is left.
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM users'));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens'));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM one_time_tokens'));
    }

    public function test_NFR_04_three_cron_runs_at_the_same_moment_finalize_every_day_exactly_once(): void
    {
        // Three runs of days:finalize as three real processes, for a user with five days that are due.
        $this->givenAUserWithOpenDays();

        $processes = [$this->start('finalize-days', '-'), $this->start('finalize-days', '-'), $this->start('finalize-days', '-')];

        self::assertSame(['ok', 'ok', 'ok'], array_map($this->outputOf(...), $processes));
        // The 10th to the 14th, each once, each checked in without a log: 70 XP a day.
        self::assertSame(
            [['2026-11-10', 70], ['2026-11-11', 70], ['2026-11-12', 70], ['2026-11-13', 70], ['2026-11-14', 70]],
            array_map(array_values(...), $this->sql()->rows('SELECT local_date, xp FROM day_summaries WHERE finalized_at IS NOT NULL ORDER BY local_date')),
        );
        self::assertSame(15, $this->sql()->value('SELECT COUNT(*) FROM xp_events'));
        self::assertSame(
            [['check_in_current' => 5, 'xp_total' => 350, 'level' => 2, 'next_day' => '2026-11-15']],
            $this->sql()->rows('SELECT check_in_current, xp_total, level, next_day FROM user_progress'),
        );
    }

    public function test_FR_LOG_07_a_log_that_races_the_finalization_of_its_day_is_stored_and_counted_or_refused_and_never_lost(): void
    {
        $this->givenAUserWithOpenDays();
        $log = implode(',', [TestData::userId(1)->value, TestData::uuid(500), '2026-11-14 10:00:00', '4']);

        $processes = [$this->start('finalize-days', '-'), $this->start('sync-log', $log), $this->start('finalize-days', '-')];
        $outputs = array_map($this->outputOf(...), $processes);

        self::assertSame(['ok', 'ok'], [$outputs[0], $outputs[2]]);
        self::assertContains($outputs[1], ['accepted', 'day_finalized']);
        $stored = $this->sql()->value('SELECT COUNT(*) FROM smoke_logs');
        $finalCount = $this->sql()->value("SELECT `count` FROM day_summaries WHERE local_date = '2026-11-14' AND finalized_at IS NOT NULL");
        // Either the log came first and is part of the final day, or the day was final and the
        // app is told so. Never a log that is stored and missing from its final day.
        self::assertSame($outputs[1] === 'accepted' ? [1, 4] : [0, 0], [$stored, $finalCount]);
    }

    /**
     * User 1 in Berlin with a plan since the 10th and every day up to the 14th checked in without a
     * log. At noon on the 17th (self::NOW) the 10th to the 14th ended more than 48 hours ago.
     */
    private function givenAUserWithOpenDays(): void
    {
        $userId = TestData::userId(1);
        (new PdoUserRepository($this->sql()))->add(TestData::user(1, passwordHash: TestAccounts::PASSWORD_HASH, createdAt: self::NOW));
        $this->sql()->execute("INSERT INTO plans VALUES (?, 'quit', 0, 'normal', 'cigarette', '2026-11-10', 'v1')", [$userId->value]);
        $this->sql()->execute("INSERT INTO baseline_history VALUES (?, '2026-11-10', 12.0, 'onboarding', NULL, NULL, NULL, NULL, 'v1')", [$userId->value]);
        $this->sql()->execute("INSERT INTO plan_history VALUES (?, '2026-11-10', 'quit', 0, 'normal', 'v1')", [$userId->value]);
        $this->sql()->execute("INSERT INTO timezone_history VALUES (?, '2026-11-10', 'Europe/Berlin')", [$userId->value]);
        $this->sql()->execute(
            "INSERT INTO user_progress VALUES (?, 0, 0, 0, 0, 0, 0, 0, '2026-11-10', '2026-11-12 23:00:00', '2026-11-22 23:00:00')",
            [$userId->value],
        );
        foreach (['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14'] as $date) {
            $this->sql()->execute(
                'INSERT INTO day_summaries (user_id, local_date, `count`, checked_in_at) VALUES (?, ?, 0, ?)',
                [$userId->value, $date, "{$date} 21:00:00"],
            );
        }
    }

    /**
     * @return iterable<string, array{string, bool|null}>
     */
    public static function requestsAboutOneAddress(): iterable
    {
        foreach (['register', 'resend-verification', 'request-password-reset'] as $action) {
            yield "{$action}, unknown address" => [$action, null];
            yield "{$action}, registration waiting for its confirmation" => [$action, true];
            yield "{$action}, confirmed account" => [$action, false];
        }
    }

    public function test_a_row_that_changed_under_a_transaction_is_reported_as_temporarily_unavailable(): void
    {
        // The safety net for a case nobody thought of: "try again" (503), not "bug" (500). Raised by
        // hand; the four tests above show the real thing no longer happens in the use cases.
        $sql = new Sql($this->session());

        try {
            $sql->execute("SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO = 1020, MESSAGE_TEXT = 'Record has changed since last read in table users'");
            self::fail('Expected the statement to fail.');
        } catch (TemporarilyUnavailable $busy) {
            self::assertSame(1020, $busy->getPrevious() instanceof PDOException ? $busy->getPrevious()->errorInfo[1] ?? null : null);
        }
    }

    public function test_the_test_above_notices_a_use_case_that_locks_the_token_first(): void
    {
        // The control: a second process that takes the locks in the forbidden order. The holder of
        // the account cannot get the token then, which is the deadlock in the making.
        $this->givenAnAccountWithTokens(true);
        $holder = $this->session();
        $holder->pdo()->beginTransaction();
        (new PdoUserRepository(new Sql($holder)))->lock(TestData::userId(1));

        $process = $this->start('token-first', 'verify-token');
        $this->waitUntilAnotherSessionWaitsForTheAccount();

        try {
            (new Sql($holder))->value('SELECT id FROM one_time_tokens WHERE id = ? FOR UPDATE NOWAIT', [TestData::uuid(10)]);
            self::fail('Expected the token to be locked by the second process.');
        } catch (TemporarilyUnavailable $locked) {
            self::assertStringContainsString('1205', (string) $locked->getPrevious()?->getMessage());
        }
        $holder->pdo()->rollBack();
        self::assertSame('ok', $this->outputOf($process));
    }

    private function emailOfTheAccount(): string
    {
        $email = $this->sql()->value('SELECT email FROM users');
        self::assertIsString($email);

        return $email;
    }

    /**
     * A connection of its own that gives up waiting for a lock after one second instead of 50.
     */
    private function session(): Database
    {
        $database = $this->newDatabase();
        $database->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

        return $database;
    }

    private function registerIn(Database $session, int $number): void
    {
        $sql = new Sql($session);
        $userId = TestData::userId($number);
        (new PdoUserRepository($sql))->add(TestData::user($number, verifiedAt: null));
        (new PdoConsentRepository($sql))->add($userId, [
            new Consent(ConsentType::Age18, 'v1', TestData::time(self::NOW)),
            new Consent(ConsentType::Art9, 'v1', TestData::time(self::NOW)),
            new Consent(ConsentType::Privacy, 'v1', TestData::time(self::NOW)),
        ]);
        (new PdoOneTimeTokenRepository($sql))->put(TestData::oneTimeToken($number + 20, $userId, "verify-{$number}"));
    }

    private function givenAnAccountWithTokens(bool $unconfirmed): void
    {
        $userId = TestData::userId(1);
        // With the password of the test accounts: confirming an address checks it (use-case.php).
        (new PdoUserRepository($this->sql()))->add(TestData::user(1, passwordHash: TestAccounts::PASSWORD_HASH, verifiedAt: $unconfirmed ? null : '2026-11-01 08:00:00', createdAt: self::NOW));
        $tokens = new PdoOneTimeTokenRepository($this->sql());
        $tokens->put(TestData::oneTimeToken(10, $userId, 'verify-token', expiresAt: '2026-11-18 12:00:00'));
        $tokens->put(TestData::oneTimeToken(11, $userId, 'reset-token', TokenPurpose::ResetPassword, '2026-11-17 13:00:00'));
        (new PdoRefreshTokenRepository($this->sql()))->add(TestData::session(20, $userId, 'key', explode('.', $this->refreshToken())[1]));
    }

    /**
     * The refresh token of the session of givenAnAccountWithTokens(), as the application of the
     * second process makes it: the secret follows from the key and the generation.
     */
    private function refreshToken(): string
    {
        return 'key.' . (new HmacRefreshSecrets($this->settings()->jwtSecret))->secret('key', 0);
    }

    /**
     * @return resource The second process, running the use case with the real application.
     */
    private function start(string $action, string $token): mixed
    {
        $process = proc_open(
            [PHP_BINARY, Paths::backend() . '/tests/Support/bin/use-case.php', self::NOW, $action, $token],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            Paths::backend(),
            getenv(),
        );
        self::assertIsResource($process);
        $this->processes[] = $process;
        $this->pipes[(int) $process] = $pipes;

        return $process;
    }

    /**
     * Waits until a session that is not ours sits in "SELECT … FROM users … FOR UPDATE".
     */
    private function waitUntilAnotherSessionWaitsForTheAccount(): void
    {
        $deadline = microtime(true) + 10;
        do {
            $waiting = $this->sql()->value(
                "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND DB = DATABASE() "
                . "AND INFO LIKE '%FROM users WHERE id = %FOR UPDATE%' AND INFO NOT LIKE '%PROCESSLIST%'",
            );
            if ($waiting === 1) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('The second process never got to wait for the lock on the account.');
    }

    /**
     * @param resource $process
     */
    private function outputOf(mixed $process): string
    {
        $pipes = $this->pipes[(int) $process] ?? [];
        self::assertIsResource($pipes[1] ?? null);
        self::assertIsResource($pipes[2] ?? null);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $this->processes = array_values(array_filter($this->processes, static fn (mixed $other): bool => $other !== $process));

        return $output;
    }
}
