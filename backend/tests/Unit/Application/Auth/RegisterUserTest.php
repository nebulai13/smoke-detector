<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SmokeDetector\Application\Account\Consent;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Account\EmailAlreadyRegistered;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\AuthLimits;
use SmokeDetector\Application\Auth\GivenConsent;
use SmokeDetector\Application\Auth\PasswordPolicy;
use SmokeDetector\Application\Auth\RegisterUser;
use SmokeDetector\Application\Auth\Registration;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Config\ConsentVersions;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;

/**
 * Registration, confirm first (HLD §4.5 "Registration", FR-ACC-01 to FR-ACC-03).
 */
final class RegisterUserTest extends AuthUseCaseTestCase
{
    public function test_FR_ACC_01_a_new_email_gets_an_unconfirmed_account(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);

        $users = $this->users->all();
        self::assertCount(1, $users);
        $user = $users[0];
        self::assertSame(SequentialIdGenerator::id(1), $user->id->value);
        self::assertSame('anna@example.org', $user->email->value);
        self::assertSame('fake-v1:' . self::PASSWORD, $user->passwordHash);
        self::assertSame('Anna', $user->displayName->value);
        self::assertSame('avatar-03', $user->avatar->key);
        self::assertSame(Locale::De, $user->locale);
        self::assertSame('Europe/Berlin', $user->timeZone->name);
        self::assertEquals($this->now(), $user->createdAt);
    }

    public function test_FR_ACC_03_the_new_account_is_not_confirmed(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);

        self::assertNull($this->users->all()[0]->emailVerifiedAt);
        self::assertFalse($this->users->all()[0]->isVerified());
    }

    public function test_FR_ACC_01_a_new_email_is_mailed_a_confirmation_link_in_the_chosen_language(): void
    {
        $this->registerUser()->execute(self::registration(locale: Locale::En), self::CLIENT);

        $mail = $this->theMail();
        self::assertSame('anna@example.org', $mail->to->value);
        self::assertSame('confirm_email', $mail->template);
        self::assertSame('confirm_email [en]', $mail->subject);
        self::assertSame('confirmUrl=https://app.example.test/verify-email?token=token-1', $mail->text);
    }

    public function test_FR_ACC_03_the_confirmation_link_is_valid_24_hours_and_stored_as_a_hash_only(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);

        $stored = $this->oneTimeTokens->all();
        self::assertCount(1, $stored);
        self::assertSame(TokenPurpose::VerifyEmail, $stored[0]->purpose);
        self::assertSame(hash('sha256', 'token-1'), $stored[0]->tokenHash);
        self::assertTrue($stored[0]->userId->equals($this->users->all()[0]->id));
        self::assertEquals(new DateTimeImmutable('2026-11-18 12:00:00 UTC'), $stored[0]->expiresAt);
        self::assertNull($stored[0]->usedAt);
    }

    public function test_FR_ACC_02_each_consent_is_stored_with_the_version_the_app_showed_and_the_time(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);

        self::assertEquals(
            [
                new Consent(ConsentType::Age18, 'age-v1', $this->now()),
                new Consent(ConsentType::Art9, 'art9-v2', $this->now()),
                new Consent(ConsentType::Privacy, 'privacy-v3', $this->now()),
            ],
            $this->consents->ofUser($this->users->all()[0]->id),
        );
    }

    /**
     * @return iterable<string, array{Registration, list<string>}>
     */
    public static function missingConsents(): iterable
    {
        yield 'age not confirmed' => [self::registration(age18: false), ['consents.age18.accepted']];
        yield 'no consent to health data' => [self::registration(art9: false), ['consents.art9.accepted']];
        yield 'privacy policy not accepted' => [self::registration(privacy: false), ['consents.privacy.accepted']];
        yield 'none' => [
            self::registration(age18: false, art9: false, privacy: false),
            ['consents.age18.accepted', 'consents.art9.accepted', 'consents.privacy.accepted'],
        ];
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('missingConsents')]
    public function test_FR_ACC_02_registration_without_all_three_consents_is_rejected(Registration $registration, array $fields): void
    {
        try {
            $this->registerUser()->execute($registration, self::CLIENT);
            self::fail('Expected the registration to be rejected.');
        } catch (ValidationFailed $failure) {
            self::assertEquals(
                array_map(static fn (string $field): FieldError => new FieldError($field, ErrorCode::NotAccepted, 'Must be accepted.'), $fields),
                $failure->errors,
            );
        }

        $this->assertNothingHappened();
    }

    /**
     * @return iterable<string, array{Registration, list<string>}>
     */
    public static function consentsToAnotherVersion(): iterable
    {
        yield 'an older version of the age confirmation' => [self::registration(age18Version: 'age-v0'), ['consents.age18.version']];
        yield 'an older version of the health data consent' => [self::registration(art9Version: 'art9-v1'), ['consents.art9.version']];
        yield 'an older version of the privacy policy' => [self::registration(privacyVersion: 'privacy-v2'), ['consents.privacy.version']];
        yield 'a version that is not in force yet' => [self::registration(privacyVersion: 'privacy-v4'), ['consents.privacy.version']];
        yield 'the version of another text' => [self::registration(age18Version: 'art9-v2'), ['consents.age18.version']];
        yield 'two texts swapped' => [self::registration(art9Version: 'privacy-v3', privacyVersion: 'art9-v2'), ['consents.art9.version', 'consents.privacy.version']];
        yield 'another spelling' => [self::registration(age18Version: 'AGE-V1'), ['consents.age18.version']];
        yield 'with a space' => [self::registration(art9Version: 'art9-v2 '), ['consents.art9.version']];
        yield 'no version' => [self::registration(privacyVersion: ''), ['consents.privacy.version']];
        yield 'all three' => [
            self::registration(age18Version: 'x', art9Version: 'y', privacyVersion: 'z'),
            ['consents.age18.version', 'consents.art9.version', 'consents.privacy.version'],
        ];
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('consentsToAnotherVersion')]
    public function test_LEG_03_a_consent_to_another_version_of_a_text_than_the_one_in_force_is_rejected_and_nothing_is_stored(Registration $registration, array $fields): void
    {
        // The record must say what the user really saw. A consent to a text the server does not
        // have in force (an app that was not updated) cannot be stored as a consent to the current one.
        try {
            $this->registerUser()->execute($registration, self::CLIENT);
            self::fail('Expected the registration to be rejected.');
        } catch (ValidationFailed $failure) {
            self::assertEquals(
                array_map(
                    static fn (string $field): FieldError => new FieldError($field, ErrorCode::OutdatedVersion, 'Is not the version of this text that is in force.'),
                    $fields,
                ),
                $failure->errors,
            );
        }

        $this->assertNothingHappened();
    }

    public function test_LEG_03_a_consent_that_is_not_given_and_names_another_version_is_reported_for_both(): void
    {
        try {
            $this->registerUser()->execute(self::registration(age18: false, age18Version: 'age-v0', privacy: false), self::CLIENT);
            self::fail('Expected the registration to be rejected.');
        } catch (ValidationFailed $failure) {
            self::assertSame(
                [['consents.age18.accepted', 'not_accepted'], ['consents.age18.version', 'outdated_version'], ['consents.privacy.accepted', 'not_accepted']],
                array_map(static fn (FieldError $error): array => [$error->field, $error->code->value], $failure->errors),
            );
        }
    }

    public function test_LEG_03_the_versions_are_checked_against_the_settings_of_this_installation(): void
    {
        // When a text changes, its version in the settings changes with it. From then on only the
        // new version is a consent.
        $register = fn (ConsentVersions $inForce): RegisterUser => new RegisterUser(
            $this->transactions,
            $this->clock,
            $this->mailBudget(),
            new PasswordPolicy(),
            $this->hasher,
            $this->users,
            $this->consents,
            $this->oneTimeTokensService(),
            $this->ids,
            $this->accountMails(),
            $inForce,
        );
        $newPolicy = new ConsentVersions('age-v1', 'art9-v2', 'privacy-v4');

        try {
            $register($newPolicy)->execute(self::registration(), self::CLIENT);
            self::fail('Expected the consent to the old privacy policy to be rejected.');
        } catch (ValidationFailed $failure) {
            self::assertSame(['consents.privacy.version'], array_map(static fn (FieldError $error): string => $error->field, $failure->errors));
        }
        $register($newPolicy)->execute(self::registration(privacyVersion: 'privacy-v4'), self::CLIENT);

        self::assertEquals(
            [
                new Consent(ConsentType::Age18, 'age-v1', $this->now()),
                new Consent(ConsentType::Art9, 'art9-v2', $this->now()),
                new Consent(ConsentType::Privacy, 'privacy-v4', $this->now()),
            ],
            $this->consents->ofUser($this->users->all()[0]->id),
        );
    }

    public function test_LEG_03_what_is_wrong_with_a_consent_can_be_asked_without_registering(): void
    {
        // The action asks first, so that one answer lists everything that is wrong with the form.
        $registerUser = $this->registerUser();
        $notAccepted = new FieldError('consents.art9.accepted', ErrorCode::NotAccepted, 'Must be accepted.');
        $outdated = new FieldError('consents.art9.version', ErrorCode::OutdatedVersion, 'Is not the version of this text that is in force.');

        self::assertSame([], $registerUser->consentViolations(ConsentType::Art9, new GivenConsent(true, 'art9-v2')));
        self::assertEquals([$notAccepted], $registerUser->consentViolations(ConsentType::Art9, new GivenConsent(false, 'art9-v2')));
        self::assertEquals([$outdated], $registerUser->consentViolations(ConsentType::Art9, new GivenConsent(true, 'art9-v1')));
        self::assertEquals([$notAccepted, $outdated], $registerUser->consentViolations(ConsentType::Art9, new GivenConsent(false, 'age-v1')));
        // Each text has its own version in force.
        self::assertSame([], $registerUser->consentViolations(ConsentType::Age18, new GivenConsent(true, 'age-v1')));
        self::assertSame([], $registerUser->consentViolations(ConsentType::Privacy, new GivenConsent(true, 'privacy-v3')));
        self::assertEquals(
            [new FieldError('consents.privacy.version', ErrorCode::OutdatedVersion, 'Is not the version of this text that is in force.')],
            $registerUser->consentViolations(ConsentType::Privacy, new GivenConsent(true, 'age-v1')),
        );
        $this->assertNothingHappened();
    }

    /**
     * @return iterable<string, array{string, string|null}> The password, and the code it is rejected with (null when it is accepted).
     */
    public static function passwords(): iterable
    {
        yield '9 characters' => [str_repeat('a', 9), 'too_short'];
        yield '10 characters' => [str_repeat('a', 10), null];
        yield '10 characters of two bytes each' => [str_repeat('ä', 10), null];
        yield '9 characters that are 18 bytes' => [str_repeat('ä', 9), 'too_short'];
        yield '200 characters' => [str_repeat('a', 200), null];
        yield '200 characters of four bytes each' => [str_repeat('🔥', 200), null];
        yield '201 characters' => [str_repeat('a', 201), 'too_long'];
        yield 'empty' => ['', 'too_short'];
        yield '10 spaces' => [str_repeat(' ', 10), null];
        yield 'not valid UTF-8' => [str_repeat("\xFF", 12), 'invalid_format'];
    }

    #[DataProvider('passwords')]
    public function test_FR_ACC_01_a_password_has_10_to_200_characters(string $password, ?string $rejectedAs): void
    {
        $accepted = $rejectedAs === null;
        try {
            $this->registerUser()->execute(self::registration(password: $password), self::CLIENT);
            self::assertTrue($accepted, 'Expected the password to be rejected.');
            self::assertSame(["fake-v1:{$password}"], [$this->users->all()[0]->passwordHash]);
        } catch (ValidationFailed $failure) {
            self::assertFalse($accepted, 'Expected the password to be accepted.');
            self::assertSame([['password', $rejectedAs]], array_map(static fn (FieldError $error): array => [$error->field, $error->code->value], $failure->errors));
            $this->assertNothingHappened();
        }
    }

    public function test_every_problem_of_a_registration_is_reported_at_once(): void
    {
        try {
            $this->registerUser()->execute(self::registration(password: 'short', art9: false), self::CLIENT);
            self::fail('Expected the registration to be rejected.');
        } catch (ValidationFailed $failure) {
            self::assertSame(
                ['password', 'consents.art9.accepted'],
                array_map(static fn (FieldError $error): string => $error->field, $failure->errors),
            );
        }
    }

    public function test_NFR_05_the_password_is_hashed_exactly_as_typed(): void
    {
        $password = "  Pass\u{00A0}wörd \t with space  ";

        $this->registerUser()->execute(self::registration(password: $password), self::CLIENT);

        self::assertSame([$password], $this->hasher->hashed);
        self::assertSame("fake-v1:{$password}", $this->users->all()[0]->passwordHash);
    }

    public function test_FR_ACC_01_the_email_of_a_confirmed_account_changes_nothing(): void
    {
        $existing = $this->givenUser('anna@example.org', 'the owners password', locale: Locale::En);
        $this->givenConsents($existing->id->value);

        $this->registerUser()->execute(
            self::registration(email: 'Anna@Example.org', password: 'the attackers password', displayName: 'Mallory'),
            self::CLIENT,
        );

        self::assertEquals([$existing], $this->users->all());
        self::assertEquals(
            [new Consent(ConsentType::Age18, 'old', new DateTimeImmutable('2026-11-01 07:00:00 UTC'))],
            $this->consents->ofUser($existing->id),
        );
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertSame([], $this->refreshTokens->all());
    }

    public function test_FR_ACC_01_the_owner_of_a_confirmed_account_is_told_so_in_their_own_language(): void
    {
        // The account is English. Whoever fills in the form now chose German.
        $this->givenUser('anna@example.org', locale: Locale::En);

        $this->registerUser()->execute(self::registration(locale: Locale::De), self::CLIENT);

        $mail = $this->theMail();
        self::assertSame('anna@example.org', $mail->to->value);
        self::assertSame('already_registered', $mail->template);
        self::assertSame('already_registered [en]', $mail->subject);
        self::assertSame(
            "loginUrl=https://app.example.test/login\nresetUrl=https://app.example.test/reset-password",
            $mail->text,
        );
        // No token was made, so there is none in the mail.
        self::assertStringNotContainsString('token', $mail->text);
    }

    public function test_FR_ACC_01_an_unconfirmed_registration_is_replaced_by_the_new_submission(): void
    {
        $this->registerUser()->execute(
            self::registration(password: 'the first password', displayName: 'First', avatar: 'avatar-01', locale: Locale::De, timeZone: 'Europe/Berlin'),
            self::CLIENT,
        );
        $firstId = $this->users->all()[0]->id;
        $this->clock->advanceSeconds(600);

        $this->registerUser()->execute(
            self::registration(password: 'the second password', displayName: 'Second', avatar: 'avatar-02', locale: Locale::En, timeZone: 'Europe/Vienna'),
            self::CLIENT,
        );

        $users = $this->users->all();
        self::assertCount(1, $users);
        self::assertTrue($users[0]->id->equals($firstId));
        self::assertSame('fake-v1:the second password', $users[0]->passwordHash);
        self::assertSame('Second', $users[0]->displayName->value);
        self::assertSame('avatar-02', $users[0]->avatar->key);
        self::assertSame(Locale::En, $users[0]->locale);
        self::assertSame('Europe/Vienna', $users[0]->timeZone->name);
        self::assertNull($users[0]->emailVerifiedAt);
        // The 24 hours until deletion start again with the new submission.
        self::assertEquals(new DateTimeImmutable('2026-11-17 12:10:00 UTC'), $users[0]->createdAt);
        // The consents are those of the new submission.
        self::assertEquals(
            [
                new Consent(ConsentType::Age18, 'age-v1', $this->now()),
                new Consent(ConsentType::Art9, 'art9-v2', $this->now()),
                new Consent(ConsentType::Privacy, 'privacy-v3', $this->now()),
            ],
            $this->consents->ofUser($firstId),
        );
    }

    public function test_a_new_registration_only_adds_rows_and_a_replaced_one_replaces_them(): void
    {
        // For the database this is the difference between a plain insert and "delete what is there
        // first". The delete finds nothing for a new account and still locks the gap its rows are
        // about to go into, which is how two unrelated registrations at the same moment used to
        // block each other (MariaDB error 1213).
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        self::assertSame(['add'], $this->consents->calls);

        $this->registerUser()->execute(self::registration(), self::CLIENT);
        self::assertSame(['add', 'replaceAll'], $this->consents->calls);
        self::assertCount(3, $this->consents->ofUser($this->users->all()[0]->id));
    }

    public function test_FR_ACC_01_replacing_an_unconfirmed_registration_invalidates_the_older_link(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        self::assertTrue($this->hasOneTimeToken('token-1', TokenPurpose::VerifyEmail));
        $this->outbox->clear();

        $this->registerUser()->execute(self::registration(locale: Locale::En), self::CLIENT);

        self::assertFalse($this->hasOneTimeToken('token-1', TokenPurpose::VerifyEmail));
        self::assertTrue($this->hasOneTimeToken('token-2', TokenPurpose::VerifyEmail));
        self::assertCount(1, $this->oneTimeTokens->all());
        $mail = $this->theMail();
        self::assertSame('confirm_email [en]', $mail->subject);
        self::assertSame('confirmUrl=https://app.example.test/verify-email?token=token-2', $mail->text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kindsOfEmail(): iterable
    {
        yield 'new email' => ['new'];
        yield 'unconfirmed registration' => ['unconfirmed'];
        yield 'confirmed account' => ['confirmed'];
    }

    #[DataProvider('kindsOfEmail')]
    public function test_NFR_05_registration_does_the_same_work_whatever_the_state_of_the_email(string $kind): void
    {
        if ($kind !== 'new') {
            $this->givenUser('anna@example.org', verified: $kind === 'confirmed');
        }

        $this->registerUser()->execute(self::registration(), self::CLIENT);

        // One password hash (the expensive part), one transaction, one mail, one hit per limit:
        // nothing a client could tell the three cases apart by.
        self::assertSame([self::PASSWORD], $this->hasher->hashed);
        self::assertSame([], $this->hasher->verified);
        self::assertSame(1, $this->transactions->commits);
        self::assertCount(1, $this->queuedMails());
        self::assertSame(1, $this->hits(AuthLimits::mailsPerClient(), self::CLIENT));
        self::assertSame(1, $this->hits(AuthLimits::mailsPerPair(), 'anna@example.org', self::CLIENT));
        self::assertSame(1, $this->hits(AuthLimits::mailsPerAddress(), 'anna@example.org'));
        self::assertCount(3, $this->counters->all());
    }

    public function test_NFR_05_one_client_address_can_register_5_times_per_hour(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->registerUser()->execute(self::registration(email: "user{$i}@example.org"), self::CLIENT);
        }
        $this->clock->advanceSeconds(3599);
        $this->outbox->clear();

        try {
            $this->registerUser()->execute(self::registration(email: 'user6@example.org'), self::CLIENT);
            self::fail('Expected the sixth registration to be refused.');
        } catch (RateLimitExceeded $exceeded) {
            self::assertSame(1, $exceeded->retryAfterSeconds);
        }
        self::assertCount(5, $this->users->all());
        self::assertSame([], $this->queuedMails());
        self::assertCount(5, $this->hasher->hashed);
        // The budget of the address is not touched by a request that the client limit refused.
        self::assertSame(0, $this->hits(AuthLimits::mailsPerAddress(), 'user6@example.org'));
        self::assertSame(0, $this->hits(AuthLimits::mailsPerPair(), 'user6@example.org', self::CLIENT));

        // Another client is not affected, and the next hour starts a new count.
        $this->registerUser()->execute(self::registration(email: 'user6@example.org'), '198.51.100.9');
        $this->clock->advanceSeconds(1);
        $this->registerUser()->execute(self::registration(email: 'user7@example.org'), self::CLIENT);
        self::assertCount(7, $this->users->all());
    }

    public function test_NFR_05_one_client_can_register_one_email_3_times_per_hour_and_does_not_lock_it_for_others(): void
    {
        $this->clock->travelTo(new DateTimeImmutable('2026-11-17 12:20:00 UTC'));
        for ($i = 1; $i <= 3; $i++) {
            $this->registerUser()->execute(self::registration(email: 'Anna@example.org'), self::CLIENT);
        }
        $this->outbox->clear();

        try {
            $this->registerUser()->execute(self::registration(email: 'anna@EXAMPLE.org'), self::CLIENT);
            self::fail('Expected the fourth registration of the email from this client to be refused.');
        } catch (RateLimitExceeded $exceeded) {
            // The window is the clock hour: 40 minutes are left of it.
            self::assertSame(2400, $exceeded->retryAfterSeconds);
        }
        self::assertSame([], $this->queuedMails());
        self::assertCount(3, $this->hasher->hashed);
        self::assertTrue($this->hasOneTimeToken('token-3', TokenPurpose::VerifyEmail));
        self::assertCount(1, $this->oneTimeTokens->all());
        // The refused request is not held against the address.
        self::assertSame(3, $this->hits(AuthLimits::mailsPerAddress(), 'anna@example.org'));

        // The limit is the pair's. Somebody else, the owner for one, registers the address all the
        // same, and this client registers another address.
        $this->registerUser()->execute(self::registration(email: 'anna@example.org'), '203.0.113.4');
        self::assertTrue($this->hasOneTimeToken('token-4', TokenPurpose::VerifyEmail));
        $this->registerUser()->execute(self::registration(email: 'bob@example.org'), self::CLIENT);
        self::assertCount(2, $this->users->all());
        self::assertCount(2, $this->queuedMails());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kindsOfEmailOverTheBudget(): iterable
    {
        yield 'new address' => ['new'];
        yield 'unconfirmed registration' => ['unconfirmed'];
        yield 'confirmed account' => ['confirmed'];
    }

    #[DataProvider('kindsOfEmailOverTheBudget')]
    public function test_NFR_05_the_eleventh_mail_to_an_address_within_an_hour_is_left_out_without_a_word(string $kind): void
    {
        if ($kind !== 'new') {
            $this->givenUser('anna@example.org', verified: $kind === 'confirmed');
        }
        // Ten requests that mail the address, from ten clients: none of them is near its own limit.
        // (Resets and resends, which count like registrations and leave the three kinds as they are.)
        for ($i = 1; $i <= 10; $i++) {
            $this->mailBudget()->spend(self::email(), "198.51.100.{$i}", $this->now());
        }
        $before = [$this->users->all(), $this->oneTimeTokens->all(), $this->consents->ofUser(UserId::fromString(SequentialIdGenerator::id(1)))];
        $this->clock->advanceSeconds(3599);

        // Answered like every other registration: no exception. A 429 here would keep the owner
        // from registering because of what strangers did.
        $this->registerUser()->execute(self::registration(password: 'another password'), self::CLIENT);

        // Nothing is mailed, so nothing is changed either: a registration that is waiting keeps its
        // data and its link, which is the one of the last mail that did go out.
        self::assertSame([], $this->queuedMails());
        self::assertEquals($before, [$this->users->all(), $this->oneTimeTokens->all(), $this->consents->ofUser(UserId::fromString(SequentialIdGenerator::id(1)))]);
        self::assertSame(0, $this->transactions->commits);
        // The password is hashed all the same, although the hash is thrown away: hashing is by far
        // the slowest step, and a quick answer would tell that others have used up the mails of
        // this address in this hour.
        self::assertSame(['another password'], $this->hasher->hashed);
        self::assertSame([], $this->hasher->verified);
        self::assertSame(11, $this->hits(AuthLimits::mailsPerAddress(), 'anna@example.org'));

        // In the next hour the address gets its mail again.
        $this->clock->advanceSeconds(1);
        $this->registerUser()->execute(self::registration(password: 'another password'), self::CLIENT);
        self::assertCount(1, $this->queuedMails());
    }

    public function test_a_rejected_registration_does_not_use_up_the_rate_limit(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            try {
                $this->registerUser()->execute(self::registration(password: 'short'), self::CLIENT);
                self::fail('Expected the registration to be rejected.');
            } catch (ValidationFailed) {
            }
        }

        $this->assertNothingCounted();
    }

    public function test_two_registrations_of_one_new_email_at_the_same_moment_both_succeed(): void
    {
        // The other request stored its account between our lookup and our insert.
        $this->users->failNextAdds = 1;

        $this->registerUser()->execute(self::registration(), self::CLIENT);

        self::assertCount(1, $this->users->all());
        self::assertSame(1, $this->transactions->rollbacks);
        self::assertSame(1, $this->transactions->commits);
        // What the failed attempt stored is gone, and one mail with the token that is stored was sent.
        self::assertCount(1, $this->oneTimeTokens->all());
        $token = $this->tokens->last();
        self::assertSame("confirmUrl=https://app.example.test/verify-email?token={$token}", $this->theMail()->text);
        self::assertTrue($this->hasOneTimeToken($token, TokenPurpose::VerifyEmail));
        self::assertSame([self::PASSWORD], $this->hasher->hashed);
    }

    public function test_a_registration_that_cannot_be_stored_sends_no_mail(): void
    {
        $this->users->failNextAdds = 2;

        try {
            $this->registerUser()->execute(self::registration(), self::CLIENT);
            self::fail('Expected the failure to be passed on.');
        } catch (EmailAlreadyRegistered $failure) {
            // What ends up in the log of the failed request: no address.
            self::assertSame('An account with this email address exists already.', $failure->getMessage());
        }

        self::assertSame([], $this->users->all());
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertSame([], $this->queuedMails());
        self::assertSame(2, $this->transactions->rollbacks);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mailsOfARegistration(): iterable
    {
        yield 'new email' => ['new', 'confirm_email'];
        yield 'unconfirmed registration' => ['unconfirmed', 'confirm_email'];
        yield 'confirmed account' => ['confirmed', 'already_registered'];
    }

    #[DataProvider('mailsOfARegistration')]
    public function test_FR_ACC_01_the_mail_is_queued_for_the_account_and_not_sent_by_the_request(string $kind, string $template): void
    {
        if ($kind !== 'new') {
            $this->givenUser('anna@example.org', verified: $kind === 'confirmed');
        }

        $this->registerUser()->execute(self::registration(), self::CLIENT);

        // The use case has no mailer at all. What it leaves behind is one row for the sender: due
        // at once, for the account the mail is about, with nothing readable of the link in it.
        $queued = $this->outbox->all();
        self::assertCount(1, $queued);
        self::assertSame($template, $queued[0]->kind->value);
        self::assertTrue($queued[0]->userId->equals($this->users->all()[0]->id));
        self::assertSame('anna@example.org', $queued[0]->to->value);
        self::assertEquals($this->now(), $queued[0]->createdAt);
        self::assertEquals($this->now(), $queued[0]->notBefore);
        self::assertSame(0, $queued[0]->attempts);
        self::assertStringNotContainsString('token-1', serialize($queued[0]));
    }

    public function test_FR_ACC_01_a_registration_whose_mail_cannot_be_queued_is_not_stored_either(): void
    {
        // The mail is queued in the transaction that stores the account and the token of its link:
        // a registration without a mail could never be confirmed.
        $this->outbox->failNextAdds = 1;

        try {
            $this->registerUser()->execute(self::registration(), self::CLIENT);
            self::fail('Expected the failure to be passed on.');
        } catch (RuntimeException $failure) {
            self::assertSame('The mail could not be queued.', $failure->getMessage());
        }

        self::assertSame([], $this->users->all());
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertSame([], $this->consents->ofUser(UserId::fromString(SequentialIdGenerator::id(1))));
        self::assertSame([], $this->outbox->all());
        self::assertSame(1, $this->transactions->rollbacks);
        self::assertSame(0, $this->transactions->commits);
    }

    private function givenConsents(string $userId): void
    {
        $this->consents->replaceAll(
            UserId::fromString($userId),
            [new Consent(ConsentType::Age18, 'old', new DateTimeImmutable('2026-11-01 07:00:00 UTC'))],
        );
    }

    private function assertNothingHappened(): void
    {
        self::assertSame([], $this->users->all());
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertSame([], $this->queuedMails());
        self::assertSame([], $this->hasher->hashed);
        self::assertSame(0, $this->transactions->commits + $this->transactions->rollbacks);
        $this->assertNothingCounted();
    }
}
