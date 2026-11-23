<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\Consent;
use SmokeDetector\Application\Account\ConsentRepository;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\OneTimeTokenRepository;
use SmokeDetector\Application\Auth\RefreshToken;
use SmokeDetector\Application\Auth\RefreshTokenRepository;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every ConsentRepository, OneTimeTokenRepository and RefreshTokenRepository does. The
 * in-memory ones (used by the use case unit tests) and the PDO ones both run these tests.
 */
trait TokenRepositoriesContract
{
    abstract protected function consents(): ConsentRepository;

    abstract protected function oneTimeTokens(): OneTimeTokenRepository;

    abstract protected function refreshTokens(): RefreshTokenRepository;

    /**
     * Makes sure the accounts exist that the rows of a test belong to.
     */
    abstract protected function givenUsers(UserId ...$ids): void;

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    abstract protected function transaction(callable $work): mixed;

    public function test_FR_ACC_02_consents_are_stored_per_user_with_type_version_and_time(): void
    {
        $anna = TestData::userId(1);
        $bob = TestData::userId(2);
        $this->givenUsers($anna, $bob);
        $at = TestData::time('2026-11-17 12:00:00');

        $this->consents()->replaceAll($anna, [
            new Consent(ConsentType::Privacy, 'p-3', $at),
            new Consent(ConsentType::Age18, 'a-1', $at),
            new Consent(ConsentType::Art9, 'h-2', $at),
        ]);
        $this->consents()->replaceAll($bob, [new Consent(ConsentType::Age18, 'a-1', TestData::time('2026-11-17 13:00:00'))]);

        self::assertEquals(
            [new Consent(ConsentType::Age18, 'a-1', $at), new Consent(ConsentType::Art9, 'h-2', $at), new Consent(ConsentType::Privacy, 'p-3', $at)],
            $this->consents()->ofUser($anna),
        );
        self::assertEquals(
            [new Consent(ConsentType::Age18, 'a-1', TestData::time('2026-11-17 13:00:00'))],
            $this->consents()->ofUser($bob),
        );
        self::assertSame([], $this->consents()->ofUser(TestData::userId(3)));
    }

    public function test_FR_ACC_02_replacing_consents_leaves_only_the_new_ones_of_that_user(): void
    {
        $anna = TestData::userId(1);
        $bob = TestData::userId(2);
        $this->givenUsers($anna, $bob);
        $old = TestData::time('2026-11-17 12:00:00');
        $new = TestData::time('2026-11-17 12:30:00');
        $this->consents()->replaceAll($anna, [new Consent(ConsentType::Age18, 'a-1', $old), new Consent(ConsentType::Art9, 'h-1', $old)]);
        $this->consents()->replaceAll($bob, [new Consent(ConsentType::Age18, 'a-1', $old)]);

        $this->consents()->replaceAll($anna, [new Consent(ConsentType::Age18, 'a-1', $new), new Consent(ConsentType::Privacy, 'p-2', $new)]);

        self::assertEquals(
            [new Consent(ConsentType::Age18, 'a-1', $new), new Consent(ConsentType::Privacy, 'p-2', $new)],
            $this->consents()->ofUser($anna),
        );
        self::assertEquals([new Consent(ConsentType::Age18, 'a-1', $old)], $this->consents()->ofUser($bob));
    }

    public function test_FR_ACC_02_the_consents_of_a_new_account_are_added(): void
    {
        $anna = TestData::userId(1);
        $bob = TestData::userId(2);
        $this->givenUsers($anna, $bob);
        $at = TestData::time('2026-11-17 12:00:00');
        $this->consents()->add($bob, [new Consent(ConsentType::Age18, 'a-1', $at)]);

        $this->consents()->add($anna, [new Consent(ConsentType::Privacy, 'p-3', $at), new Consent(ConsentType::Age18, 'a-1', $at)]);

        self::assertEquals(
            [new Consent(ConsentType::Age18, 'a-1', $at), new Consent(ConsentType::Privacy, 'p-3', $at)],
            $this->consents()->ofUser($anna),
        );
        self::assertEquals([new Consent(ConsentType::Age18, 'a-1', $at)], $this->consents()->ofUser($bob));
    }

    public function test_FR_ACC_02_adding_consents_to_an_account_that_has_them_is_refused(): void
    {
        // add() is for a new account only. It must not quietly leave two records of one consent.
        $anna = TestData::userId(1);
        $this->givenUsers($anna);
        $consents = [new Consent(ConsentType::Age18, 'a-1', TestData::time('2026-11-17 12:00:00'))];
        $this->consents()->add($anna, $consents);

        try {
            $this->consents()->add($anna, $consents);
            self::fail('Expected the second add to be refused.');
        } catch (\Throwable $refused) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $refused);
        }
        self::assertEquals($consents, $this->consents()->ofUser($anna));
    }

    public function test_a_one_time_token_is_found_by_its_hash_and_purpose_only(): void
    {
        $anna = TestData::userId(1);
        $this->givenUsers($anna);
        $token = TestData::oneTimeToken(10, $anna, 'the-token', TokenPurpose::VerifyEmail);
        $this->oneTimeTokens()->put($token);
        $this->oneTimeTokens()->put(TestData::oneTimeToken(11, $anna, 'another-token', TokenPurpose::ResetPassword));

        foreach (['findByHash', 'lockByHash'] as $read) {
            $found = $this->transaction(fn () => $this->oneTimeTokens()->{$read}(TokenPurpose::VerifyEmail, hash('sha256', 'the-token')));
            $wrongPurpose = $this->transaction(fn () => $this->oneTimeTokens()->{$read}(TokenPurpose::ResetPassword, hash('sha256', 'the-token')));
            $unknown = $this->transaction(fn () => $this->oneTimeTokens()->{$read}(TokenPurpose::VerifyEmail, hash('sha256', 'no-such-token')));
            // The token itself is not what is stored.
            $raw = $this->transaction(fn () => $this->oneTimeTokens()->{$read}(TokenPurpose::VerifyEmail, 'the-token'));

            self::assertEquals($token, $found, $read);
            self::assertNull($wrongPurpose, $read);
            self::assertNull($unknown, $read);
            self::assertNull($raw, $read);
        }
        // The plain read needs no transaction: it locks nothing.
        self::assertEquals($token, $this->oneTimeTokens()->findByHash(TokenPurpose::VerifyEmail, hash('sha256', 'the-token')));
    }

    public function test_a_one_time_token_is_marked_as_used(): void
    {
        $anna = TestData::userId(1);
        $bob = TestData::userId(2);
        $this->givenUsers($anna, $bob);
        $this->oneTimeTokens()->put(TestData::oneTimeToken(10, $anna, 'a'));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(11, $bob, 'b'));

        $this->oneTimeTokens()->markUsed(TestData::uuid(10), TestData::time('2026-11-17 12:34:56'));

        self::assertEquals(
            TestData::oneTimeToken(10, $anna, 'a', usedAt: '2026-11-17 12:34:56'),
            $this->oneTimeTokens()->findByHash(TokenPurpose::VerifyEmail, hash('sha256', 'a')),
        );
        self::assertEquals(TestData::oneTimeToken(11, $bob, 'b'), $this->oneTimeTokens()->findByHash(TokenPurpose::VerifyEmail, hash('sha256', 'b')));
    }

    public function test_NFR_05_failed_attempts_are_counted_on_the_one_token(): void
    {
        $anna = TestData::userId(1);
        $bob = TestData::userId(2);
        $this->givenUsers($anna, $bob);
        $this->oneTimeTokens()->put(TestData::oneTimeToken(10, $anna, 'a'));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(11, $bob, 'b', failedAttempts: 3));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(12, $anna, 'reset', TokenPurpose::ResetPassword));

        $this->oneTimeTokens()->addFailedAttempt(TestData::uuid(10));
        $this->oneTimeTokens()->addFailedAttempt(TestData::uuid(10));
        $this->oneTimeTokens()->addFailedAttempt(TestData::uuid(11));
        $this->oneTimeTokens()->addFailedAttempt(TestData::uuid(99));

        // Stored and read back with everything else as it was, by both ways of reading.
        self::assertEquals(TestData::oneTimeToken(10, $anna, 'a', failedAttempts: 2), $this->oneTimeTokens()->findByHash(TokenPurpose::VerifyEmail, hash('sha256', 'a')));
        self::assertEquals(
            TestData::oneTimeToken(11, $bob, 'b', failedAttempts: 4),
            $this->transaction(fn () => $this->oneTimeTokens()->lockByHash(TokenPurpose::VerifyEmail, hash('sha256', 'b'))),
        );
        self::assertEquals(TestData::oneTimeToken(12, $anna, 'reset', TokenPurpose::ResetPassword), $this->oneTimeTokens()->findByHash(TokenPurpose::ResetPassword, hash('sha256', 'reset')));
        // Using the token up leaves the count alone.
        $this->oneTimeTokens()->markUsed(TestData::uuid(10), TestData::time('2026-11-17 12:34:56'));
        self::assertEquals(
            TestData::oneTimeToken(10, $anna, 'a', usedAt: '2026-11-17 12:34:56', failedAttempts: 2),
            $this->oneTimeTokens()->findByHash(TokenPurpose::VerifyEmail, hash('sha256', 'a')),
        );
    }

    public function test_NFR_05_a_one_time_token_is_deleted_alone(): void
    {
        $anna = TestData::userId(1);
        $bob = TestData::userId(2);
        $this->givenUsers($anna, $bob);
        $this->oneTimeTokens()->put(TestData::oneTimeToken(10, $anna, 'anna-verify'));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(11, $anna, 'anna-reset', TokenPurpose::ResetPassword));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(12, $bob, 'bob-verify'));

        $this->oneTimeTokens()->delete(TestData::uuid(10));
        $this->oneTimeTokens()->delete(TestData::uuid(99));

        self::assertFalse($this->hasOneTimeToken('anna-verify', TokenPurpose::VerifyEmail));
        self::assertTrue($this->hasOneTimeToken('anna-reset', TokenPurpose::ResetPassword));
        self::assertTrue($this->hasOneTimeToken('bob-verify', TokenPurpose::VerifyEmail));
        // The user can get a new token of that purpose afterwards.
        $this->oneTimeTokens()->put(TestData::oneTimeToken(13, $anna, 'anna-verify-new'));
        self::assertEquals(TestData::oneTimeToken(13, $anna, 'anna-verify-new'), $this->oneTimeTokens()->findByHash(TokenPurpose::VerifyEmail, hash('sha256', 'anna-verify-new')));
    }

    public function test_FR_ACC_03_a_new_one_time_token_starts_without_the_failed_attempts_of_the_one_it_replaces(): void
    {
        $anna = TestData::userId(1);
        $this->givenUsers($anna);
        $this->oneTimeTokens()->put(TestData::oneTimeToken(10, $anna, 'worn', failedAttempts: 4));

        $this->oneTimeTokens()->put(TestData::oneTimeToken(14, $anna, 'fresh'));

        self::assertEquals(TestData::oneTimeToken(10, $anna, 'fresh'), $this->oneTimeTokens()->findByHash(TokenPurpose::VerifyEmail, hash('sha256', 'fresh')));
    }

    public function test_FR_ACC_01_a_new_one_time_token_replaces_the_one_of_the_same_user_and_purpose(): void
    {
        $anna = TestData::userId(1);
        $bob = TestData::userId(2);
        $this->givenUsers($anna, $bob);
        $this->oneTimeTokens()->put(TestData::oneTimeToken(10, $anna, 'anna-verify-used', usedAt: '2026-11-17 12:00:00'));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(12, $anna, 'anna-reset', TokenPurpose::ResetPassword));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(13, $bob, 'bob-verify'));

        $newest = TestData::oneTimeToken(14, $anna, 'anna-verify-new', expiresAt: '2026-11-19 09:00:00');
        $this->oneTimeTokens()->put($newest);

        // The older link is gone, used or not. The new token is stored as given, so unused, in the
        // row of the old one: the id is that row's.
        self::assertFalse($this->hasOneTimeToken('anna-verify-used', TokenPurpose::VerifyEmail));
        self::assertEquals(
            TestData::oneTimeToken(10, $anna, 'anna-verify-new', expiresAt: '2026-11-19 09:00:00'),
            $this->oneTimeTokens()->findByHash(TokenPurpose::VerifyEmail, hash('sha256', 'anna-verify-new')),
        );
        self::assertTrue($this->hasOneTimeToken('anna-reset', TokenPurpose::ResetPassword));
        self::assertTrue($this->hasOneTimeToken('bob-verify', TokenPurpose::VerifyEmail));
        // And it can be used like any other.
        $this->oneTimeTokens()->markUsed(TestData::uuid(10), TestData::time('2026-11-18 10:00:00'));
        self::assertEquals(
            TestData::time('2026-11-18 10:00:00'),
            $this->oneTimeTokens()->findByHash(TokenPurpose::VerifyEmail, hash('sha256', 'anna-verify-new'))?->usedAt,
        );
    }

    public function test_one_time_tokens_that_are_expired_or_used_are_deleted(): void
    {
        $users = [TestData::userId(1), TestData::userId(2), TestData::userId(3), TestData::userId(4)];
        $this->givenUsers(...$users);
        $this->oneTimeTokens()->put(TestData::oneTimeToken(10, $users[0], 'expired', expiresAt: '2026-11-17 11:59:59'));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(11, $users[1], 'expires-now', expiresAt: '2026-11-17 12:00:00'));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(12, $users[2], 'used', expiresAt: '2026-11-18 12:00:00', usedAt: '2026-11-17 11:00:00'));
        $this->oneTimeTokens()->put(TestData::oneTimeToken(13, $users[3], 'live', expiresAt: '2026-11-17 12:00:01'));

        $deleted = $this->oneTimeTokens()->deleteExpiredAndUsed(TestData::time('2026-11-17 12:00:00'));

        self::assertSame(3, $deleted);
        self::assertFalse($this->hasOneTimeToken('expired', TokenPurpose::VerifyEmail));
        self::assertFalse($this->hasOneTimeToken('expires-now', TokenPurpose::VerifyEmail));
        self::assertFalse($this->hasOneTimeToken('used', TokenPurpose::VerifyEmail));
        self::assertTrue($this->hasOneTimeToken('live', TokenPurpose::VerifyEmail));
        self::assertSame(0, $this->oneTimeTokens()->deleteExpiredAndUsed(TestData::time('2026-11-17 12:00:00')));
    }

    public function test_a_session_is_found_by_the_hash_of_its_key_and_locked_by_its_id_with_every_value(): void
    {
        $anna = TestData::userId(1);
        $this->givenUsers($anna);
        $session = TestData::session(20, $anna, 'key-a', 'secret-1', rotatedAt: '2026-11-17 12:30:00', revokedAt: '2026-11-17 13:00:00', deviceLabel: 'Annas iPhone 📱', generation: 7);
        $plain = TestData::session(21, $anna, 'key-b', 'secret-1');
        $this->refreshTokens()->add($session);
        $this->refreshTokens()->add($plain);

        self::assertEquals($session, $this->refreshTokens()->findByKeyHash(hash('sha256', 'key-a')));
        self::assertEquals($plain, $this->refreshTokens()->findByKeyHash(hash('sha256', 'key-b')));
        self::assertEquals($session, $this->transaction(fn () => $this->refreshTokens()->lock(TestData::uuid(20))));
        // Not by the hash of the whole token, not by the key itself, not by anything unknown.
        self::assertNull($this->refreshTokens()->findByKeyHash(hash('sha256', 'key-a.secret-1')));
        self::assertNull($this->refreshTokens()->findByKeyHash('key-a'));
        self::assertNull($this->refreshTokens()->findByKeyHash(hash('sha256', 'key-c')));
        self::assertNull($this->transaction(fn () => $this->refreshTokens()->lock(TestData::uuid(22))));
    }

    public function test_NFR_05_a_refresh_replaces_the_token_of_the_session_in_place(): void
    {
        $anna = TestData::userId(1);
        $this->givenUsers($anna);
        $this->refreshTokens()->add(TestData::session(20, $anna, 'key-a', 'secret-1', deviceLabel: 'Phone', generation: 4));
        $this->refreshTokens()->add(TestData::session(21, $anna, 'key-b', 'secret-1', generation: 4));

        $this->refreshTokens()->rotate(TestData::uuid(20), hash('sha256', 'key-a.secret-2'), 5, TestData::time('2026-11-20 08:00:00'), TestData::time('2027-01-19 08:00:00'));

        // The hash of the new token and the number of its generation, in the same row.
        self::assertEquals(
            TestData::session(20, $anna, 'key-a', 'secret-2', expiresAt: '2027-01-19 08:00:00', rotatedAt: '2026-11-20 08:00:00', deviceLabel: 'Phone', generation: 5),
            $this->refreshTokens()->findByKeyHash(hash('sha256', 'key-a')),
        );
        self::assertEquals(TestData::session(21, $anna, 'key-b', 'secret-1', generation: 4), $this->refreshTokens()->findByKeyHash(hash('sha256', 'key-b')));
    }

    public function test_FR_ACC_04_revoking_a_session_ends_that_one_and_keeps_the_first_time(): void
    {
        $anna = TestData::userId(1);
        $this->givenUsers($anna);
        $this->refreshTokens()->add(TestData::session(20, $anna, 'key-a', 'secret-1'));
        $this->refreshTokens()->add(TestData::session(21, $anna, 'key-b', 'secret-1'));

        $this->refreshTokens()->revoke(TestData::uuid(20), TestData::time('2026-11-17 12:30:00'));
        $this->refreshTokens()->revoke(TestData::uuid(20), TestData::time('2026-11-17 12:45:00'));
        $this->refreshTokens()->revoke(TestData::uuid(99), TestData::time('2026-11-17 12:45:00'));

        self::assertEquals(TestData::time('2026-11-17 12:30:00'), $this->refreshTokens()->findByKeyHash(hash('sha256', 'key-a'))?->revokedAt);
        self::assertNull($this->refreshTokens()->findByKeyHash(hash('sha256', 'key-b'))?->revokedAt);
    }

    public function test_FR_ACC_05_revoking_all_sessions_of_a_user_leaves_other_users_alone(): void
    {
        $anna = TestData::userId(1);
        $bob = TestData::userId(2);
        $this->givenUsers($anna, $bob);
        $this->refreshTokens()->add(TestData::session(20, $anna, 'anna-phone', 's'));
        $this->refreshTokens()->add(TestData::session(21, $anna, 'anna-laptop', 's', revokedAt: '2026-11-17 12:10:00'));
        $this->refreshTokens()->add(TestData::session(22, $bob, 'bob-phone', 's'));

        $this->refreshTokens()->revokeAllOfUser($anna, TestData::time('2026-11-17 12:30:00'));

        self::assertEquals(TestData::time('2026-11-17 12:30:00'), $this->refreshTokens()->findByKeyHash(hash('sha256', 'anna-phone'))?->revokedAt);
        // Revoked earlier: the first time stays.
        self::assertEquals(TestData::time('2026-11-17 12:10:00'), $this->refreshTokens()->findByKeyHash(hash('sha256', 'anna-laptop'))?->revokedAt);
        self::assertNull($this->refreshTokens()->findByKeyHash(hash('sha256', 'bob-phone'))?->revokedAt);
    }

    public function test_NFR_05_only_the_sessions_of_a_user_that_were_used_last_are_kept(): void
    {
        $anna = TestData::userId(1);
        $bob = TestData::userId(2);
        $this->givenUsers($anna, $bob);
        // By last use (refresh, or login when never refreshed): c, e, d, a, b. Of d and e, used in
        // the same second, the one with the later id is the newer login.
        $this->refreshTokens()->add(TestData::session(20, $anna, 'a', 's', createdAt: '2026-11-10 08:00:00', rotatedAt: '2026-11-12 08:00:00'));
        $this->refreshTokens()->add(TestData::session(21, $anna, 'b', 's', createdAt: '2026-11-11 08:00:00'));
        $this->refreshTokens()->add(TestData::session(22, $anna, 'c', 's', createdAt: '2026-11-09 08:00:00', rotatedAt: '2026-11-16 08:00:00'));
        $this->refreshTokens()->add(TestData::session(23, $anna, 'd', 's', createdAt: '2026-11-14 08:00:00'));
        $this->refreshTokens()->add(TestData::session(24, $anna, 'e', 's', createdAt: '2026-11-13 08:00:00', rotatedAt: '2026-11-14 08:00:00'));
        $this->refreshTokens()->add(TestData::session(25, $bob, 'bob-old', 's', createdAt: '2026-10-01 08:00:00'));

        self::assertSame(0, $this->refreshTokens()->deleteAllButLastUsedOfUser($anna, 5));
        self::assertSame(2, $this->refreshTokens()->deleteAllButLastUsedOfUser($anna, 3));

        $left = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'bob-old'] as $key) {
            if ($this->refreshTokens()->findByKeyHash(hash('sha256', $key)) !== null) {
                $left[] = $key;
            }
        }
        self::assertSame(['c', 'd', 'e', 'bob-old'], $left);
        self::assertSame(1, $this->refreshTokens()->deleteAllButLastUsedOfUser($anna, 2));
        self::assertNull($this->refreshTokens()->findByKeyHash(hash('sha256', 'd')));
        self::assertSame(2, $this->refreshTokens()->deleteAllButLastUsedOfUser($anna, 0));
        self::assertNotNull($this->refreshTokens()->findByKeyHash(hash('sha256', 'bob-old')));
    }

    public function test_FR_ACC_04_sessions_that_have_ended_do_not_count_towards_the_sessions_that_are_kept(): void
    {
        $anna = TestData::userId(1);
        $this->givenUsers($anna);
        $this->refreshTokens()->add(TestData::session(30, $anna, 'live-old', 's', createdAt: '2026-11-10 08:00:00'));
        $this->refreshTokens()->add(TestData::session(31, $anna, 'live-new', 's', createdAt: '2026-11-12 08:00:00'));
        // Logged out, and by every date newer than the two devices that are still logged in. If
        // these counted, logging out and in again on one device would end the session of another.
        $this->refreshTokens()->add(TestData::session(32, $anna, 'ended-1', 's', revokedAt: '2026-11-15 09:00:00', createdAt: '2026-11-15 08:00:00'));
        $this->refreshTokens()->add(TestData::session(33, $anna, 'ended-2', 's', rotatedAt: '2026-11-16 08:00:00', revokedAt: '2026-11-16 09:00:00', createdAt: '2026-11-14 08:00:00'));
        $found = fn (string $key): bool => $this->refreshTokens()->findByKeyHash(hash('sha256', $key)) !== null;

        // Two live sessions and room for two: nothing to do.
        self::assertSame(0, $this->refreshTokens()->deleteAllButLastUsedOfUser($anna, 2));
        self::assertSame([true, true, true, true], array_map($found, ['live-old', 'live-new', 'ended-1', 'ended-2']));

        // Room for one: the live session that was unused longest goes. The ended ones are not
        // touched here; the cleanup deletes them.
        self::assertSame(1, $this->refreshTokens()->deleteAllButLastUsedOfUser($anna, 1));
        self::assertSame([false, true, true, true], array_map($found, ['live-old', 'live-new', 'ended-1', 'ended-2']));
    }

    public function test_NFR_05_sessions_that_are_expired_or_revoked_are_deleted(): void
    {
        $anna = TestData::userId(1);
        $this->givenUsers($anna);
        $this->refreshTokens()->add(TestData::session(20, $anna, 'expired', 's', expiresAt: '2026-11-17 11:59:59'));
        $this->refreshTokens()->add(TestData::session(21, $anna, 'expires-now', 's', expiresAt: '2026-11-17 12:00:00'));
        $this->refreshTokens()->add(TestData::session(22, $anna, 'revoked', 's', revokedAt: '2026-11-17 11:00:00'));
        $this->refreshTokens()->add(TestData::session(23, $anna, 'refreshed-and-live', 's', expiresAt: '2026-11-17 12:00:01', rotatedAt: '2026-11-17 11:00:00'));

        $deleted = $this->refreshTokens()->deleteExpiredAndRevoked(TestData::time('2026-11-17 12:00:00'));

        self::assertSame(3, $deleted);
        self::assertNull($this->refreshTokens()->findByKeyHash(hash('sha256', 'expired')));
        self::assertNull($this->refreshTokens()->findByKeyHash(hash('sha256', 'expires-now')));
        self::assertNull($this->refreshTokens()->findByKeyHash(hash('sha256', 'revoked')));
        self::assertNotNull($this->refreshTokens()->findByKeyHash(hash('sha256', 'refreshed-and-live')));
        self::assertSame(0, $this->refreshTokens()->deleteExpiredAndRevoked(TestData::time('2026-11-17 12:00:00')));
    }

    public function test_a_time_with_a_fraction_of_a_second_is_stored_to_the_second(): void
    {
        // The real clock has microseconds, the DATETIME columns do not. What comes back is the
        // time cut to the second, never rounded up: a value written and read back is not "later".
        $anna = TestData::userId(1);
        $this->givenUsers($anna);
        $exact = new \DateTimeImmutable('2026-11-17 12:00:00.987654 UTC');
        $session = TestData::session(20, $anna, 'key-a', 'secret-1');
        $this->refreshTokens()->add(new RefreshToken($session->id, $session->userId, $session->keyHash, $session->tokenHash, null, $exact, null, $exact, null));

        $this->refreshTokens()->revoke($session->id, $exact);
        $this->refreshTokens()->rotate($session->id, $session->tokenHash, 1, $exact, $exact);

        $stored = $this->refreshTokens()->findByKeyHash($session->keyHash);
        $cut = TestData::time('2026-11-17 12:00:00');
        self::assertEquals([$cut, $cut, $cut, $cut], [$stored?->createdAt, $stored?->rotatedAt, $stored?->expiresAt, $stored?->revokedAt]);
    }

    private function hasOneTimeToken(string $token, TokenPurpose $purpose): bool
    {
        return $this->oneTimeTokens()->findByHash($purpose, hash('sha256', $token)) !== null;
    }
}
