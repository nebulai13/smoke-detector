<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Account;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\ProfileChange;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\AccountUseCaseTestCase;

/**
 * PATCH /me (HLD §4.5 "Account", FR-ACC-09): display name, avatar, language and time zone.
 */
final class UpdateProfileTest extends AccountUseCaseTestCase
{
    public function test_FR_ACC_09_all_four_profile_values_can_be_changed_at_once(): void
    {
        $user = $this->givenUser();

        $this->updateProfile()->execute($user->id, new ProfileChange(
            DisplayName::fromInput('Anna B.'),
            Avatar::fromInput('avatar-11'),
            Locale::En,
            TimeZone::fromInput('America/New_York'),
        ));

        $stored = $this->users->find($user->id);
        self::assertSame(
            ['Anna B.', 'avatar-11', Locale::En, 'America/New_York'],
            [$stored?->displayName->value, $stored?->avatar->key, $stored?->locale, $stored?->timeZone->name],
        );
    }

    public function test_FR_ACC_09_only_what_is_given_is_changed(): void
    {
        $changes = [
            'displayName' => new ProfileChange(displayName: DisplayName::fromInput('Anna B.')),
            'avatar' => new ProfileChange(avatar: Avatar::fromInput('avatar-11')),
            'locale' => new ProfileChange(locale: Locale::En),
            'timeZone' => new ProfileChange(timeZone: TimeZone::fromInput('America/New_York')),
        ];

        $changed = [];
        foreach (array_values($changes) as $number => $change) {
            $user = $this->givenUser("user{$number}@example.org");
            $this->updateProfile()->execute($user->id, $change);
            $stored = $this->users->find($user->id);
            self::assertInstanceOf(User::class, $stored);
            $changed[] = [$stored->displayName->value, $stored->avatar->key, $stored->locale->value, $stored->timeZone->name];
        }

        // The stored profile is "Stored Name", avatar-07, de, Europe/Vienna.
        self::assertSame(
            [
                ['Anna B.', 'avatar-07', 'de', 'Europe/Vienna'],
                ['Stored Name', 'avatar-11', 'de', 'Europe/Vienna'],
                ['Stored Name', 'avatar-07', 'en', 'Europe/Vienna'],
                ['Stored Name', 'avatar-07', 'de', 'America/New_York'],
            ],
            $changed,
        );
    }

    public function test_FR_ACC_09_email_password_and_the_dates_of_the_account_stay_as_they_are(): void
    {
        $user = $this->givenUser();

        $this->updateProfile()->execute($user->id, new ProfileChange(displayName: DisplayName::fromInput('Anna B.')));

        $stored = $this->users->find($user->id);
        self::assertInstanceOf(User::class, $stored);
        self::assertEquals(
            [$user->id, $user->email, $user->passwordHash, $user->emailVerifiedAt, $user->createdAt],
            [$stored->id, $stored->email, $stored->passwordHash, $stored->emailVerifiedAt, $stored->createdAt],
        );
    }

    public function test_FR_ACC_09_nobody_else_is_changed(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $bob = $this->givenUser('bob@example.org');

        $this->updateProfile()->execute($anna->id, new ProfileChange(displayName: DisplayName::fromInput('Anna B.')));

        self::assertEquals($bob, $this->users->find($bob->id));
    }

    public function test_FR_ACC_09_a_change_of_nothing_is_rejected(): void
    {
        $user = $this->givenUser();

        try {
            $this->updateProfile()->execute($user->id, new ProfileChange());
            self::fail('Expected the empty change to be rejected.');
        } catch (ValidationFailed $rejected) {
            self::assertCount(1, $rejected->errors);
            self::assertSame('', $rejected->errors[0]->field);
            self::assertSame(ErrorCode::Required, $rejected->errors[0]->code);
            self::assertSame('At least one of displayName, avatar, locale and timeZone is required.', $rejected->errors[0]->message);
        }
        self::assertEquals($user, $this->users->find($user->id));
        self::assertSame(0, $this->transactions->commits + $this->transactions->rollbacks);
    }

    public function test_the_change_is_one_transaction(): void
    {
        $user = $this->givenUser();

        $this->updateProfile()->execute($user->id, new ProfileChange(locale: Locale::En));

        // The account is read under a lock (which the in-memory repository refuses outside a
        // transaction), so a change that arrives at the same moment is not overwritten with old values.
        self::assertSame([1, 0], [$this->transactions->commits, $this->transactions->rollbacks]);
    }

    public function test_FR_ACC_08_an_account_that_does_not_exist_any_more_is_reported_as_gone(): void
    {
        $this->givenUser();

        $this->expectException(AccountNotFound::class);

        $this->updateProfile()->execute(TestData::userId(999), new ProfileChange(locale: Locale::En));
    }
}
