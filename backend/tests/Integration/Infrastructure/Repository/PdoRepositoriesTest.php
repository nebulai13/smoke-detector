<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Repository;

use LogicException;
use PDOException;
use SmokeDetector\Application\TemporarilyUnavailable;
use SmokeDetector\Application\Account\Consent;
use SmokeDetector\Application\Account\ConsentRepository;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Auth\OneTimeTokenRepository;
use SmokeDetector\Application\Auth\RefreshTokenRepository;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\Mail\QueuedMail;
use SmokeDetector\Application\Plan\BaselineAlreadyRecorded;
use SmokeDetector\Application\Plan\BaselineHistory;
use SmokeDetector\Application\Plan\PlanRepository;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\RateLimit\RateLimitCounters;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\PdoTransactionRunner;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoBaselineHistory;
use SmokeDetector\Infrastructure\Repository\PdoConsentRepository;
use SmokeDetector\Infrastructure\Repository\PdoMailOutbox;
use SmokeDetector\Infrastructure\Repository\PdoOneTimeTokenRepository;
use SmokeDetector\Infrastructure\Repository\PdoPlanRepository;
use SmokeDetector\Infrastructure\Repository\PdoRateLimitCounters;
use SmokeDetector\Infrastructure\Repository\PdoRefreshTokenRepository;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\Contract\MailOutboxContract;
use SmokeDetector\Tests\Support\Contract\PlanRepositoriesContract;
use SmokeDetector\Tests\Support\Contract\RateLimitCountersContract;
use SmokeDetector\Tests\Support\Contract\TokenRepositoriesContract;
use SmokeDetector\Tests\Support\Contract\UserRepositoryContract;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestData;
use UnexpectedValueException;

/**
 * The PDO repositories against the real MariaDB: the contract they share with the in-memory ones,
 * and what only a database can show (locks, cascades, what the stored rows look like).
 */
final class PdoRepositoriesTest extends IntegrationTestCase
{
    use UserRepositoryContract;
    use TokenRepositoriesContract;
    use RateLimitCountersContract;
    use PlanRepositoriesContract;
    use MailOutboxContract;

    private Database $database;

    protected function setUp(): void
    {
        // A connection of its own, as the application has: the test's own connection looks at the
        // rows from outside.
        $this->database = $this->newDatabase();
    }

    public function test_an_account_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->users()->add(TestData::user(1, 'anna@example.org', 'the-hash', verifiedAt: '2026-11-17 12:00:00', createdAt: '2026-11-17 11:00:00'));

        self::assertSame(
            [[
                'id' => TestData::uuid(1),
                'email' => 'anna@example.org',
                'password_hash' => 'the-hash',
                'display_name' => 'Änna 🔥',
                'avatar_key' => 'avatar-03',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'email_verified_at' => '2026-11-17 12:00:00',
                'created_at' => '2026-11-17 11:00:00',
                'token_version' => 0,
            ]],
            $this->sql()->rows('SELECT * FROM users'),
        );
    }

    public function test_a_duplicate_id_is_an_error_and_not_a_taken_email(): void
    {
        $this->users()->add(TestData::user(1, 'anna@example.org'));

        $this->expectException(PDOException::class);

        $this->users()->add(TestData::user(1, 'bob@example.org'));
    }

    public function test_NFR_05_a_locked_account_cannot_be_locked_by_a_second_request(): void
    {
        $this->users()->add(TestData::user(1));

        $this->transaction(function (): void {
            self::assertNotNull($this->users()->lock(TestData::userId(1)));
            // Reading without a lock still works. Locking has to wait, which NOWAIT turns into an error.
            self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM users WHERE id = ?', [TestData::uuid(1)]));
            $this->assertLocked('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT', TestData::uuid(1));
        });

        // Released with the transaction.
        self::assertSame(TestData::uuid(1), $this->sql()->value('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT', [TestData::uuid(1)]));
    }

    public function test_NFR_05_a_token_that_is_being_redeemed_cannot_be_read_for_redeeming_by_a_second_request(): void
    {
        $this->users()->add(TestData::user(1));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(10, TestData::userId(1), 'one-time'));
        $this->refreshTokens()->add(TestData::session(20, TestData::userId(1), 'key', 'secret'));

        $this->transaction(function (): void {
            self::assertNotNull($this->oneTimeTokens()->lockByHash(TokenPurpose::VerifyEmail, hash('sha256', 'one-time')));
            self::assertNotNull($this->refreshTokens()->lock(TestData::uuid(20)));

            $this->assertLocked('SELECT id FROM one_time_tokens WHERE id = ? FOR UPDATE NOWAIT', TestData::uuid(10));
            $this->assertLocked('SELECT id FROM refresh_tokens WHERE id = ? FOR UPDATE NOWAIT', TestData::uuid(20));
        });
    }

    public function test_FR_ACC_03_deleting_an_unconfirmed_registration_takes_its_consents_and_tokens_with_it(): void
    {
        $old = TestData::userId(1);
        $kept = TestData::userId(2);
        $this->users()->add(TestData::user(1, verifiedAt: null, createdAt: '2026-11-10 00:00:00'));
        $this->users()->add(TestData::user(2));
        foreach ([$old, $kept] as $index => $userId) {
            $this->consents()->replaceAll($userId, [new Consent(ConsentType::Age18, 'v1', TestData::time('2026-11-10 00:00:00'))]);
            $this->oneTimeTokens()->put(TestData::oneTimeToken(10 + $index, $userId, "one-time-{$index}"));
            $this->refreshTokens()->add(TestData::session(20 + $index, $userId, "key-{$index}", 'secret'));
        }

        self::assertSame(1, $this->users()->deleteUnverifiedCreatedBefore(TestData::time('2026-11-16 00:00:00')));

        self::assertSame([TestData::uuid(2)], $this->sql()->column('SELECT id FROM users'));
        self::assertSame([TestData::uuid(2)], $this->sql()->column('SELECT user_id FROM consents'));
        self::assertSame([TestData::uuid(2)], $this->sql()->column('SELECT user_id FROM one_time_tokens'));
        self::assertSame([TestData::uuid(2)], $this->sql()->column('SELECT user_id FROM refresh_tokens'));
    }

    public function test_rows_cannot_exist_without_their_account(): void
    {
        foreach ([
            fn () => $this->consents()->add(TestData::userId(9), [new Consent(ConsentType::Age18, 'v1', TestData::time('2026-11-17 12:00:00'))]),
            fn () => $this->oneTimeTokens()->put(TestData::oneTimeToken(10, TestData::userId(9), 'x')),
            fn () => $this->refreshTokens()->add(TestData::session(20, TestData::userId(9), 'x', 'y')),
            fn () => $this->plans()->save(TestData::plan(9)),
            fn () => $this->baselines()->append(TestData::baseline(9, '2026-11-17', 120)),
        ] as $insert) {
            try {
                $insert();
                self::fail('Expected the foreign key to refuse the row.');
            } catch (PDOException $refused) {
                self::assertSame('23000', $refused->getCode());
            }
        }
    }

    public function test_NFR_05_two_tokens_cannot_have_the_same_hash(): void
    {
        $this->users()->add(TestData::user(1));
        $this->users()->add(TestData::user(2));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(10, TestData::userId(1), 'same'));
        $this->refreshTokens()->add(TestData::session(20, TestData::userId(1), 'key', 'same'));

        foreach ([
            // Another user's token with the same hash (for the same user it would replace the token).
            fn () => $this->oneTimeTokens()->put(TestData::oneTimeToken(11, TestData::userId(2), 'same')),
            // The same key, the same whole token.
            fn () => $this->refreshTokens()->add(TestData::session(21, TestData::userId(1), 'key', 'other')),
            fn () => $this->sql()->execute(
                'INSERT INTO refresh_tokens (id, user_id, key_hash, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                [TestData::uuid(22), TestData::uuid(1), hash('sha256', 'other-key'), hash('sha256', 'key.same')],
            ),
        ] as $insert) {
            try {
                $insert();
                self::fail('Expected the unique index to refuse the row.');
            } catch (PDOException $refused) {
                self::assertSame('23000', $refused->getCode());
            }
        }
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM one_time_tokens'));
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens'));
    }

    public function test_tokens_are_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->users()->add(TestData::user(1));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(10, TestData::userId(1), 'one-time', TokenPurpose::ResetPassword, '2026-11-17 13:00:00', '2026-11-17 12:30:00', 3));
        $this->refreshTokens()->add(TestData::session(20, TestData::userId(1), 'key', 'secret', '2027-01-16 12:00:00', '2026-11-18 00:00:00', '2026-11-19 00:00:00', 'Annas iPhone', generation: 12));

        self::assertSame(
            [[
                'id' => TestData::uuid(10),
                'user_id' => TestData::uuid(1),
                'purpose' => 'reset_password',
                'token_hash' => hash('sha256', 'one-time'),
                'expires_at' => '2026-11-17 13:00:00',
                'used_at' => '2026-11-17 12:30:00',
                'failed_attempts' => 3,
            ]],
            $this->sql()->rows('SELECT * FROM one_time_tokens'),
        );
        self::assertSame(
            [[
                'id' => TestData::uuid(20),
                'user_id' => TestData::uuid(1),
                'key_hash' => hash('sha256', 'key'),
                'token_hash' => hash('sha256', 'key.secret'),
                'device_label' => 'Annas iPhone',
                'created_at' => '2026-11-17 12:00:00',
                'rotated_at' => '2026-11-18 00:00:00',
                'expires_at' => '2027-01-16 12:00:00',
                'revoked_at' => '2026-11-19 00:00:00',
                'generation' => 12,
            ]],
            $this->sql()->rows('SELECT * FROM refresh_tokens'),
        );
    }

    public function test_FR_ACC_06_a_plan_is_stored_in_the_columns_of_the_design(): void
    {
        $this->users()->add(TestData::user(1));
        $this->users()->add(TestData::user(2));
        $this->plans()->save(TestData::plan(1, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::HeatedTobacco, '2026-11-17', 'v1'));
        $this->plans()->save(TestData::plan(2, Goal::quit(), TaperSpeed::Fast, ProductType::Shisha, '2026-11-18', 'v1'));
        // Saving again must not leave a second row behind.
        $this->plans()->save(TestData::plan(2, Goal::quit(), TaperSpeed::Fast, ProductType::Shisha, '2026-11-18', 'v1'));

        self::assertSame(
            [
                ['user_id' => TestData::uuid(1), 'goal_mode' => 'reduce', 'target' => 5, 'taper_speed' => 'gentle', 'default_product' => 'heated_tobacco', 'start_date' => '2026-11-17', 'rules_version' => 'v1', 'last_session_before' => null],
                ['user_id' => TestData::uuid(2), 'goal_mode' => 'quit', 'target' => 0, 'taper_speed' => 'fast', 'default_product' => 'shisha', 'start_date' => '2026-11-18', 'rules_version' => 'v1', 'last_session_before' => null],
            ],
            $this->sql()->rows('SELECT * FROM plans ORDER BY user_id'),
        );
    }

    public function test_FR_PLAN_02_a_baseline_entry_is_stored_in_the_columns_of_the_design(): void
    {
        $this->users()->add(TestData::user(1));
        $this->baselines()->append(TestData::baseline(1, '2026-11-17', 120));
        $this->baselines()->append(TestData::baseline(1, '2026-11-23', 74, BaselineSource::Weekly, 7.25, 8.5, 7, 28, 'v1'));

        self::assertSame(
            [
                ['user_id' => TestData::uuid(1), 'valid_from' => '2026-11-17', 'value' => '12.0', 'source' => 'onboarding', 'w_mean' => null, 'm_mean' => null, 'w_days' => null, 'm_days' => null, 'rules_version' => 'v1'],
                ['user_id' => TestData::uuid(1), 'valid_from' => '2026-11-23', 'value' => '7.4', 'source' => 'weekly', 'w_mean' => 7.25, 'm_mean' => 8.5, 'w_days' => 7, 'm_days' => 28, 'rules_version' => 'v1'],
            ],
            $this->sql()->rows('SELECT * FROM baseline_history ORDER BY valid_from'),
        );
    }

    public function test_FR_PLAN_02_a_refused_second_entry_leaves_the_transaction_usable(): void
    {
        // The use case that ran into it may want to go on (a weekly update that runs twice).
        $this->users()->add(TestData::user(1));
        $this->baselines()->append(TestData::baseline(1, '2026-11-17', 120));

        $this->transaction(function (): void {
            try {
                $this->baselines()->append(TestData::baseline(1, '2026-11-17', 60));
                self::fail('Expected the second entry for the same day to be refused.');
            } catch (BaselineAlreadyRecorded) {
            }
            $this->baselines()->append(TestData::baseline(1, '2026-11-18', 60, BaselineSource::Weekly));
        });

        self::assertSame(['12.0', '6.0'], $this->sql()->column('SELECT value FROM baseline_history ORDER BY valid_from'));
    }

    public function test_a_plan_row_that_contradicts_itself_stops_the_request(): void
    {
        // "quit" with a target is not a goal. It must not travel on as either of the two.
        $this->users()->add(TestData::user(1));
        $this->sql()->execute(
            'INSERT INTO plans (user_id, goal_mode, target, taper_speed, default_product, start_date, rules_version) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [TestData::uuid(1), 'quit', 5, 'normal', 'cigarette', '2026-11-17', 'v1'],
        );

        $this->expectException(UnexpectedValueException::class);

        $this->plans()->find(TestData::userId(1));
    }

    public function test_NFR_05_a_rate_limit_hit_cannot_be_counted_inside_a_transaction(): void
    {
        // It would be rolled back together with the failed attempt it is there to count.
        foreach ([
            fn () => $this->counters()->increment('k', TestData::time('2026-11-17 12:00:00')),
            fn () => $this->counters()->decrement('k', TestData::time('2026-11-17 12:00:00')),
            fn () => $this->counters()->deleteKeysEndingWith('k'),
            fn () => $this->counters()->deleteKey('k'),
        ] as $count) {
            try {
                $this->transaction($count);
                self::fail('Expected counting inside a transaction to be refused.');
            } catch (LogicException $refused) {
                self::assertStringContainsString('outside', $refused->getMessage());
            }
        }

        self::assertSame([], $this->sql()->rows('SELECT * FROM rate_limits'));
    }

    public function test_NFR_05_a_rate_limit_hit_is_stored_at_once_for_every_other_request_to_see(): void
    {
        $this->counters()->increment('login.account:abc', TestData::time('2026-11-17 12:15:00'));
        $this->counters()->increment('login.account:abc', TestData::time('2026-11-17 12:15:00'));

        // Seen from the test's own connection: committed.
        self::assertSame(
            [['key' => 'login.account:abc', 'window_start' => '2026-11-17 12:15:00', 'hits' => 2]],
            $this->sql()->rows('SELECT * FROM rate_limits'),
        );
    }

    public function test_a_queued_mail_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsers(TestData::userId(1));
        $mail = new QueuedMail(
            TestData::uuid(10),
            TestData::userId(1),
            MailKind::ResetPassword,
            Locale::En,
            EmailAddress::fromInput('anna@example.org'),
            'c2VhbGVk',
            // 12:00:00 and 12:05:00 UTC, written as local time elsewhere.
            new \DateTimeImmutable('2026-11-17 13:00:00', new \DateTimeZone('Europe/Berlin')),
            new \DateTimeImmutable('2026-11-17 21:05:00', new \DateTimeZone('Asia/Tokyo')),
            2,
        );

        $this->transaction(fn () => $this->outbox()->add($mail));

        self::assertSame(
            [[
                'id' => TestData::uuid(10),
                'user_id' => TestData::uuid(1),
                'template' => 'reset_password',
                'locale' => 'en',
                'recipient' => 'anna@example.org',
                'sealed_values' => 'c2VhbGVk',
                'created_at' => '2026-11-17 12:00:00',
                'not_before' => '2026-11-17 12:05:00',
                'attempts' => 2,
            ]],
            $this->sql()->rows('SELECT * FROM mail_outbox'),
        );
    }

    public function test_FR_ACC_08_a_queued_mail_is_deleted_with_its_account_and_cannot_exist_without_one(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->transaction(function (): void {
            $this->outbox()->add(TestData::queuedMail(10, 1));
            $this->outbox()->add(TestData::queuedMail(11, 2));
        });

        $this->users()->delete(TestData::userId(1));

        self::assertSame([TestData::uuid(11)], $this->sql()->column('SELECT id FROM mail_outbox'));
        try {
            $this->transaction(fn () => $this->outbox()->add(TestData::queuedMail(12, 3)));
            self::fail('Expected a mail without an account to be refused.');
        } catch (PDOException) {
        }
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));
    }

    public function test_two_runs_at_the_same_moment_never_take_the_same_mail_and_do_not_wait_for_each_other(): void
    {
        $this->givenUsers(TestData::userId(1));
        $this->transaction(function (): void {
            for ($i = 1; $i <= 4; $i++) {
                $this->outbox()->add(TestData::queuedMail(10 + $i, createdAt: "2026-11-17 11:0{$i}:00"));
            }
        });
        $now = TestData::time('2026-11-17 12:00:00');
        $lease = TestData::time('2026-11-17 12:10:00');
        // A second run with a connection of its own, which gives up after a second instead of
        // waiting 50 for a lock: if it had to wait for the first run, this test would fail quickly.
        $second = $this->newDatabase();
        $second->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $secondOutbox = new PdoMailOutbox(new Sql($second));

        $ofTheFirst = [];
        $ofTheSecond = [];
        $this->transaction(function () use (&$ofTheFirst, &$ofTheSecond, $second, $secondOutbox, $now, $lease): void {
            $ofTheFirst = $this->outbox()->claimDue($now, 2, $lease);

            // While the first run's transaction is still open:
            $ofTheSecond = (new PdoTransactionRunner($second))->run(static fn (): array => $secondOutbox->claimDue($now, 10, $lease));
        });

        /** @var list<QueuedMail> $ofTheFirst */
        /** @var list<QueuedMail> $ofTheSecond */
        $ids = static fn (QueuedMail ...$mails): array => array_values(array_map(static fn (QueuedMail $mail): string => $mail->id, $mails));
        self::assertSame([TestData::uuid(11), TestData::uuid(12)], $ids(...$ofTheFirst));
        self::assertSame([], array_intersect($ids(...$ofTheFirst), $ids(...$ofTheSecond)));
        // Once both are done, what either took is due for nobody, and what neither took (the
        // second run passes over every row the first one has looked at) is there for the next look.
        $ofTheThird = (new PdoTransactionRunner($second))->run(static fn (): array => $secondOutbox->claimDue($now, 10, $lease));
        self::assertSame([], array_intersect([...$ids(...$ofTheFirst), ...$ids(...$ofTheSecond)], $ids(...$ofTheThird)));
        $all = [...$ids(...$ofTheFirst), ...$ids(...$ofTheSecond), ...$ids(...$ofTheThird)];
        sort($all);
        self::assertSame([TestData::uuid(11), TestData::uuid(12), TestData::uuid(13), TestData::uuid(14)], $all);
    }

    public function test_a_run_never_waits_for_a_mail_that_another_run_holds_and_it_did_not_take_itself(): void
    {
        // Found on CI: of three runs at the same moment one failed with a deadlock. Two runs whose
        // looks overlap hold different mails each. Marking the own mails as taken must then touch
        // the own mails only. On a small table the database otherwise walks over every row to find
        // them, waits at the mails of the other run, and the other run waits for it.
        $this->givenUsers(TestData::userId(1));
        $this->transaction(function (): void {
            for ($i = 1; $i <= 12; $i++) {
                $this->outbox()->add(TestData::queuedMail(10 + $i, createdAt: sprintf('2026-11-17 11:%02d:00', $i)));
            }
        });
        $now = TestData::time('2026-11-17 12:00:00');
        $lease = TestData::time('2026-11-17 12:10:00');
        // A second run that gives up after a second instead of waiting 50 for a lock.
        $second = $this->newDatabase();
        $second->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $secondOutbox = new PdoMailOutbox(new Sql($second));

        $taken = [];
        $this->transaction(function () use (&$taken, $second, $secondOutbox, $now, $lease): void {
            // Another run holds the newest mail and has not finished.
            (new Sql($this->database))->rows('SELECT id FROM mail_outbox WHERE id = ? FOR UPDATE', [TestData::uuid(22)]);

            $taken = (new PdoTransactionRunner($second))->run(static fn (): array => $secondOutbox->claimDue($now, 10, $lease));
        });

        /** @var list<QueuedMail> $taken */
        self::assertSame(
            array_map(static fn (int $number): string => TestData::uuid($number), range(11, 20)),
            array_map(static fn (QueuedMail $mail): string => $mail->id, $taken),
        );
        // The ten are held until the lease ends. The two others are as they were.
        self::assertSame(
            [['not_before' => '2026-11-17 11:11:00', 'mails' => 1], ['not_before' => '2026-11-17 11:12:00', 'mails' => 1], ['not_before' => '2026-11-17 12:10:00', 'mails' => 10]],
            $this->sql()->rows('SELECT not_before, COUNT(*) AS mails FROM mail_outbox GROUP BY not_before ORDER BY not_before'),
        );
    }

    public function test_a_mail_whose_template_or_language_this_release_does_not_know_is_left_alone(): void
    {
        // After a rollback to an older release: the mails of the newer one (a new kind of mail, a
        // new language) must neither stop the sender nor be thrown away. They wait for a release
        // that knows them.
        $this->givenUsers(TestData::userId(1));
        $this->transaction(fn () => $this->outbox()->add(TestData::queuedMail(10)));
        $insert = 'INSERT INTO mail_outbox (id, user_id, template, locale, recipient, sealed_values, created_at, not_before, attempts) '
            . "VALUES (?, ?, ?, ?, 'user1@example.org', 'from-the-future', '2026-11-17 10:00:00', '2026-11-17 10:00:00', 0)";
        $this->sql()->execute($insert, [TestData::uuid(11), TestData::uuid(1), 'weekly_summary', 'de']);
        $this->sql()->execute($insert, [TestData::uuid(12), TestData::uuid(1), 'confirm_email', 'fr']);

        $taken = $this->transaction(fn (): array => $this->outbox()->claimDue(TestData::time('2026-11-17 12:00:00'), 10, TestData::time('2026-11-17 12:10:00')));

        self::assertEquals([TestData::queuedMail(10)], $taken);
        self::assertSame(
            ['2026-11-17 10:00:00', '2026-11-17 10:00:00'],
            $this->sql()->column("SELECT not_before FROM mail_outbox WHERE sealed_values = 'from-the-future'"),
        );
    }

    protected function users(): UserRepository
    {
        return new PdoUserRepository(new Sql($this->database));
    }

    protected function consents(): ConsentRepository
    {
        return new PdoConsentRepository(new Sql($this->database));
    }

    protected function oneTimeTokens(): OneTimeTokenRepository
    {
        return new PdoOneTimeTokenRepository(new Sql($this->database));
    }

    protected function refreshTokens(): RefreshTokenRepository
    {
        return new PdoRefreshTokenRepository(new Sql($this->database));
    }

    protected function counters(): RateLimitCounters
    {
        return new PdoRateLimitCounters(new Sql($this->database), $this->database);
    }

    protected function plans(): PlanRepository
    {
        return new PdoPlanRepository(new Sql($this->database));
    }

    protected function baselines(): BaselineHistory
    {
        return new PdoBaselineHistory(new Sql($this->database));
    }

    protected function outbox(): MailOutbox
    {
        return new PdoMailOutbox(new Sql($this->database));
    }

    protected function storedMails(): array
    {
        $mails = [];
        foreach ($this->sql()->rows("SELECT * FROM mail_outbox WHERE sealed_values <> 'from-the-future' ORDER BY id") as $row) {
            self::assertIsString($row['id']);
            self::assertIsString($row['user_id']);
            self::assertIsString($row['template']);
            self::assertIsString($row['locale']);
            self::assertIsString($row['recipient']);
            self::assertIsString($row['sealed_values']);
            self::assertIsString($row['created_at']);
            self::assertIsString($row['not_before']);
            self::assertIsInt($row['attempts']);
            $mails[] = new QueuedMail(
                $row['id'],
                UserId::fromString($row['user_id']),
                MailKind::from($row['template']),
                Locale::from($row['locale']),
                EmailAddress::fromInput($row['recipient']),
                $row['sealed_values'],
                TestData::time($row['created_at']),
                TestData::time($row['not_before']),
                $row['attempts'],
            );
        }

        return $mails;
    }

    protected function givenUsers(UserId ...$ids): void
    {
        foreach ($ids as $id) {
            $number = (int) substr($id->value, -12);
            if ($this->users()->find($id) === null) {
                $this->users()->add(TestData::user($number));
            }
        }
    }

    protected function transaction(callable $work): mixed
    {
        return (new PdoTransactionRunner($this->database))->run($work);
    }

    /**
     * Asserts that the row is locked by the code under test: the test's own connection cannot lock it.
     */
    private function assertLocked(string $selectForUpdateNowait, string $id): void
    {
        try {
            $this->sql()->rows($selectForUpdateNowait, [$id]);
            self::fail('Expected the row to be locked.');
        } catch (TemporarilyUnavailable $blocked) {
            // 1205: lock wait timeout, which is what NOWAIT reports at once.
            $cause = $blocked->getPrevious();
            self::assertInstanceOf(PDOException::class, $cause);
            self::assertSame(1205, $cause->errorInfo[1] ?? null, $cause->getMessage());
        }
    }
}
