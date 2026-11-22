<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use DateTimeImmutable;
use DateTimeZone;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\EmailAlreadyRegistered;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every UserRepository does. The in-memory one (used by the use case unit tests) and the PDO
 * one both run these tests, so the fake cannot drift away from the real thing.
 */
trait UserRepositoryContract
{
    abstract protected function users(): UserRepository;

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    abstract protected function transaction(callable $work): mixed;

    public function test_an_added_account_is_found_by_id_and_by_email_with_every_value(): void
    {
        $user = TestData::user(1, 'anna@example.org', '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHQ$hash+with/special=chars');
        $this->users()->add($user);
        $this->users()->add(TestData::user(2));

        self::assertEquals($user, $this->users()->find($user->id));
        self::assertEquals($user, $this->users()->findByEmail(EmailAddress::fromInput('anna@example.org')));
        self::assertEquals($user, $this->transaction(fn () => $this->users()->lock($user->id)));
    }

    public function test_an_unconfirmed_account_is_stored_without_a_confirmation_time(): void
    {
        $user = TestData::user(1, verifiedAt: null, locale: Locale::En);
        $this->users()->add($user);

        $found = $this->users()->find($user->id);
        self::assertEquals($user, $found);
        self::assertNull($found?->emailVerifiedAt);
        self::assertFalse($found?->isVerified());
    }

    public function test_an_unknown_account_is_not_found(): void
    {
        $this->users()->add(TestData::user(1));

        self::assertNull($this->users()->find(TestData::userId(2)));
        self::assertNull($this->users()->findByEmail(EmailAddress::fromInput('nobody@example.org')));
        self::assertNull($this->transaction(fn () => $this->users()->lock(TestData::userId(2))));
    }

    public function test_FR_ACC_01_an_email_address_belongs_to_one_account_only(): void
    {
        $this->users()->add(TestData::user(1, 'anna@example.org'));

        try {
            $this->users()->add(TestData::user(2, 'anna@example.org'));
            self::fail('Expected the second account with the same email to be refused.');
        } catch (EmailAlreadyRegistered $refused) {
            // The message may be logged: no address in it.
            self::assertStringNotContainsString('anna', $refused->getMessage());
        }

        self::assertNull($this->users()->find(TestData::userId(2)));
    }

    public function test_addresses_that_differ_in_one_character_are_different_accounts(): void
    {
        $this->users()->add(TestData::user(1, 'anna@example.org'));
        $this->users()->add(TestData::user(2, 'an-na@example.org'));
        $this->users()->add(TestData::user(3, 'anna@example.com'));

        self::assertEquals(TestData::userId(1), $this->users()->findByEmail(EmailAddress::fromInput('anna@example.org'))?->id);
        self::assertEquals(TestData::userId(2), $this->users()->findByEmail(EmailAddress::fromInput('an-na@example.org'))?->id);
        self::assertEquals(TestData::userId(3), $this->users()->findByEmail(EmailAddress::fromInput('anna@example.com'))?->id);
    }

    public function test_update_writes_every_value_of_the_account_back(): void
    {
        $this->users()->add(TestData::user(1, 'anna@example.org', verifiedAt: null));
        $this->users()->add(TestData::user(2));
        $changed = TestData::user(
            1,
            'anna.new@example.org',
            'another-hash',
            verifiedAt: '2026-11-17 12:00:01',
            createdAt: '2026-11-17 11:00:00',
            displayName: 'Bob',
            locale: Locale::En,
        );

        $this->users()->update($changed);

        self::assertEquals($changed, $this->users()->find($changed->id));
        self::assertNull($this->users()->findByEmail(EmailAddress::fromInput('anna@example.org')));
        // Nobody else is touched.
        self::assertEquals(TestData::user(2), $this->users()->find(TestData::userId(2)));
    }

    public function test_FR_ACC_01_update_cannot_take_the_email_of_another_account(): void
    {
        $this->users()->add(TestData::user(1, 'anna@example.org'));
        $this->users()->add(TestData::user(2, 'bob@example.org'));

        try {
            $this->users()->update(TestData::user(2, 'anna@example.org'));
            self::fail('Expected the update to be refused.');
        } catch (EmailAlreadyRegistered) {
        }

        self::assertEquals(TestData::user(2, 'bob@example.org'), $this->users()->find(TestData::userId(2)));
    }

    public function test_times_are_kept_as_the_same_moment_whatever_zone_they_come_in(): void
    {
        $berlin = new DateTimeImmutable('2026-11-17 13:00:00', new DateTimeZone('Europe/Berlin'));
        $user = TestData::user(1)->verifiedAt($berlin);
        $this->users()->add($user);

        $found = $this->users()->find($user->id);

        self::assertEquals(TestData::time('2026-11-17 12:00:00'), $found?->emailVerifiedAt);
    }

    public function test_FR_ACC_03_only_unconfirmed_accounts_created_before_the_moment_are_deleted(): void
    {
        $this->users()->add(TestData::user(1, verifiedAt: null, createdAt: '2026-11-16 11:59:59'));
        $this->users()->add(TestData::user(2, verifiedAt: null, createdAt: '2026-11-16 12:00:00'));
        $this->users()->add(TestData::user(3, verifiedAt: null, createdAt: '2026-11-17 09:00:00'));
        $this->users()->add(TestData::user(4, verifiedAt: '2026-11-01 00:00:00', createdAt: '2026-10-01 00:00:00'));
        $this->users()->add(TestData::user(5, verifiedAt: null, createdAt: '2026-10-01 00:00:00'));

        $deleted = $this->users()->deleteUnverifiedCreatedBefore(TestData::time('2026-11-16 12:00:00'));

        self::assertSame(2, $deleted);
        self::assertNull($this->users()->find(TestData::userId(1)));
        self::assertNotNull($this->users()->find(TestData::userId(2)));
        self::assertNotNull($this->users()->find(TestData::userId(3)));
        self::assertNotNull($this->users()->find(TestData::userId(4)));
        self::assertNull($this->users()->find(TestData::userId(5)));
        self::assertSame(0, $this->users()->deleteUnverifiedCreatedBefore(TestData::time('2026-11-16 12:00:00')));
    }

    public function test_FR_ACC_08_a_deleted_account_is_gone_and_its_email_address_is_free_again(): void
    {
        $this->users()->add(TestData::user(1, 'anna@example.org'));
        $this->users()->add(TestData::user(2, 'bob@example.org'));

        $this->users()->delete(TestData::userId(1));

        self::assertNull($this->users()->find(TestData::userId(1)));
        self::assertNull($this->users()->findByEmail(EmailAddress::fromInput('anna@example.org')));
        self::assertEquals(TestData::user(2, 'bob@example.org'), $this->users()->find(TestData::userId(2)));
        // The address can be registered again, as a new account.
        $this->users()->add(TestData::user(3, 'anna@example.org'));
        self::assertEquals(TestData::user(3, 'anna@example.org'), $this->users()->findByEmail(EmailAddress::fromInput('anna@example.org')));
    }

    public function test_deleting_an_account_that_does_not_exist_does_nothing(): void
    {
        $this->users()->add(TestData::user(1));

        $this->users()->delete(TestData::userId(2));

        self::assertNotNull($this->users()->find(TestData::userId(1)));
    }
}
