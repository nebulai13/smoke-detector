<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Auth\AuthLimits;
use SmokeDetector\Application\Auth\TokenHash;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Application\Housekeeping\PurgeExpiredData;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\RateLimitKey;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * POST /auth/register, /auth/verify-email and /auth/resend-verification through the real
 * application and database (HLD §4.5 "Registration", FR-ACC-01 to FR-ACC-03, LEG-03).
 */
final class RegistrationTest extends HttpTestCase
{
    public function test_FR_ACC_01_registration_answers_check_your_inbox_and_stores_an_unconfirmed_account(): void
    {
        $response = $this->call('POST', '/auth/register', TestAccounts::registration());

        self::assertSame(202, $response->status());
        self::assertSame(['status' => 'verification_sent'], $response->json());
        self::assertSame('application/json', $response->header('Content-Type'));

        $users = $this->sql()->rows('SELECT * FROM users');
        self::assertCount(1, $users);
        self::assertIsString($users[0]['id']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $users[0]['id']);
        self::assertSame(
            [
                'email' => 'anna@example.org',
                'display_name' => 'Anna',
                'avatar_key' => 'avatar-03',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'email_verified_at' => null,
                'created_at' => '2026-11-17 12:00:00',
                'token_version' => 0,
            ],
            array_diff_key($users[0], ['id' => 0, 'password_hash' => 0]),
        );
    }

    public function test_NFR_05_the_password_is_stored_as_an_argon2id_hash_only(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());

        $hash = $this->sql()->value('SELECT password_hash FROM users');
        self::assertIsString($hash);
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', $hash);
        self::assertTrue(password_verify(TestAccounts::PASSWORD, $hash));
        self::assertStringNotContainsString(TestAccounts::PASSWORD, $this->everythingStoredAndLogged());
    }

    public function test_FR_ACC_02_the_three_consents_are_stored_with_the_version_the_app_showed_and_the_time(): void
    {
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-17 18:30:15 UTC'));

        $this->call('POST', '/auth/register', TestAccounts::registration(['consents' => [
            'age18' => ['accepted' => true, 'version' => 'test-age18-v1'],
            'art9' => ['accepted' => true, 'version' => 'test-art9-v1'],
            'privacy' => ['accepted' => true, 'version' => 'test-privacy-v1'],
        ]]));

        $userId = $this->sql()->value('SELECT id FROM users');
        // LEG-03: who agreed to what, in which version of the text, and when.
        self::assertSame(
            [
                ['user_id' => $userId, 'type' => 'age18', 'version' => 'test-age18-v1', 'granted_at' => '2026-11-17 18:30:15'],
                ['user_id' => $userId, 'type' => 'art9', 'version' => 'test-art9-v1', 'granted_at' => '2026-11-17 18:30:15'],
                ['user_id' => $userId, 'type' => 'privacy', 'version' => 'test-privacy-v1', 'granted_at' => '2026-11-17 18:30:15'],
            ],
            $this->sql()->rows('SELECT * FROM consents ORDER BY type'),
        );
    }

    public function test_FR_ACC_02_the_app_can_ask_which_versions_of_the_consent_texts_are_in_force(): void
    {
        $response = $this->call('GET', '/legal/consents');

        self::assertSame(200, $response->status());
        self::assertSame(['age18' => 'test-age18-v1', 'art9' => 'test-art9-v1', 'privacy' => 'test-privacy-v1'], $response->json());
        // The versions the test registrations are made with (TestAccounts::registration()).
        self::assertSame(TestAccounts::CONSENT_VERSIONS, $response->json());
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>, list<string>}>
     */
    public static function missingConsents(): iterable
    {
        $no = ['accepted' => false];

        yield 'age not confirmed' => [['age18' => $no], ['consents.age18.accepted']];
        yield 'no consent to health data' => [['art9' => $no], ['consents.art9.accepted']];
        yield 'privacy policy not accepted' => [['privacy' => $no], ['consents.privacy.accepted']];
        yield 'none given' => [['age18' => $no, 'art9' => $no, 'privacy' => $no], ['consents.age18.accepted', 'consents.art9.accepted', 'consents.privacy.accepted']];
    }

    /**
     * @param array<string, array<string, mixed>> $notGiven
     * @param list<string>                        $fields
     */
    #[DataProvider('missingConsents')]
    public function test_FR_ACC_02_registration_without_all_three_consents_is_rejected(array $notGiven, array $fields): void
    {
        $response = $this->callInvalid('POST', '/auth/register', TestAccounts::registration(['consents' => TestAccounts::consents($notGiven)]));

        $problem = self::assertProblem(422, 'validation_failed', $response);
        self::assertSame(
            array_map(static fn (string $field): array => ['field' => $field, 'code' => 'not_accepted'], $fields),
            self::fieldCodes($problem),
        );
        $this->assertNothingStoredOrSent();
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>, list<string>}>
     */
    public static function consentsToAnotherVersion(): iterable
    {
        yield 'an older privacy policy' => [['privacy' => ['version' => 'test-privacy-v0']], ['consents.privacy.version']];
        yield 'a version that is not in force yet' => [['art9' => ['version' => 'test-art9-v2']], ['consents.art9.version']];
        yield 'the version of another text' => [['age18' => ['version' => 'test-art9-v1']], ['consents.age18.version']];
        yield 'another spelling' => [['age18' => ['version' => 'TEST-AGE18-V1']], ['consents.age18.version']];
        yield 'all three' => [
            ['age18' => ['version' => '1'], 'art9' => ['version' => '1'], 'privacy' => ['version' => '1']],
            ['consents.age18.version', 'consents.art9.version', 'consents.privacy.version'],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $otherVersions
     * @param list<string>                        $fields
     */
    #[DataProvider('consentsToAnotherVersion')]
    public function test_LEG_03_a_consent_to_another_version_of_a_text_than_the_one_in_force_is_rejected_and_nothing_is_stored(array $otherVersions, array $fields): void
    {
        // The app sends the version of each text it showed. When that is not the version in force,
        // the user agreed to another text: the app has to be updated, and no consent is recorded.
        $response = $this->call('POST', '/auth/register', TestAccounts::registration(['consents' => TestAccounts::consents($otherVersions)]));

        $problem = self::assertProblem(422, 'validation_failed', $response);
        self::assertSame(
            array_map(static fn (string $field): array => ['field' => $field, 'code' => 'outdated_version'], $fields),
            self::fieldCodes($problem),
        );
        // The message says what is wrong and repeats nothing of what was sent.
        self::assertStringNotContainsStringIgnoringCase('test-', $response->body());
        $this->assertNothingStoredOrSent();
    }

    public function test_LEG_03_a_version_that_cannot_be_one_is_an_outdated_version_too(): void
    {
        foreach (['', str_repeat('v', 33)] as $version) {
            $response = $this->callInvalid('POST', '/auth/register', TestAccounts::registration(['consents' => TestAccounts::consents(['privacy' => ['version' => $version]])]));

            self::assertSame(
                [['field' => 'consents.privacy.version', 'code' => 'outdated_version']],
                self::fieldCodes(self::assertProblem(422, 'validation_failed', $response)),
            );
        }
        $this->assertNothingStoredOrSent();
    }

    public function test_FR_ACC_02_registration_without_the_consents_object_or_with_a_consent_left_out_is_rejected(): void
    {
        $withoutConsents = TestAccounts::registration();
        unset($withoutConsents['consents']);
        self::assertSame(
            [['field' => 'consents', 'code' => 'required']],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/register', $withoutConsents))),
        );

        $oneLeftOut = TestAccounts::registration(['consents' => array_diff_key(TestAccounts::consents(), ['art9' => 0])]);
        self::assertSame(
            [['field' => 'consents.art9', 'code' => 'required']],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/register', $oneLeftOut))),
        );

        // A consent without the version of its text is no record of what the user agreed to.
        $withoutVersion = TestAccounts::registration(['consents' => ['age18' => ['accepted' => true]] + TestAccounts::consents()]);
        self::assertSame(
            [['field' => 'consents.age18.version', 'code' => 'required']],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/register', $withoutVersion))),
        );
        $versionOnly = TestAccounts::registration(['consents' => ['privacy' => ['version' => 'test-privacy-v1']] + TestAccounts::consents()]);
        self::assertSame(
            [['field' => 'consents.privacy.accepted', 'code' => 'required']],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/register', $versionOnly))),
        );

        // "Yes" in any other form than true is not a consent, and a version is text.
        $wrongTypes = TestAccounts::registration(['consents' => TestAccounts::consents([
            'age18' => ['accepted' => 'true'], 'art9' => ['accepted' => 1], 'privacy' => ['version' => 1],
        ])]);
        self::assertSame(
            [
                ['field' => 'consents.age18.accepted', 'code' => 'wrong_type'],
                ['field' => 'consents.art9.accepted', 'code' => 'wrong_type'],
                ['field' => 'consents.privacy.version', 'code' => 'wrong_type'],
            ],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/register', $wrongTypes))),
        );

        // What a client built against the earlier contract sends: the bare consents, no versions.
        $bare = TestAccounts::registration(['consents' => ['age18' => true, 'art9' => true, 'privacy' => true]]);
        self::assertSame(
            [
                ['field' => 'consents.age18', 'code' => 'wrong_type'],
                ['field' => 'consents.art9', 'code' => 'wrong_type'],
                ['field' => 'consents.privacy', 'code' => 'wrong_type'],
            ],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/register', $bare))),
        );

        $unknownMember = TestAccounts::registration(['consents' => TestAccounts::consents(['art9' => ['signedBy' => 'Anna']])]);
        self::assertSame(
            [['field' => 'consents.art9.signedBy', 'code' => 'unknown_field']],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/register', $unknownMember))),
        );
        $this->assertNothingStoredOrSent();
    }

    public function test_FR_ACC_01_every_invalid_field_of_a_registration_is_reported_at_once(): void
    {
        $response = $this->callInvalid('POST', '/auth/register', [
            'email' => 'not-an-address',
            'password' => 'too short',
            'displayName' => 'A',
            'avatar' => 'my-own.png',
            'locale' => 'fr',
            'timeZone' => 'Mars/Olympus',
            'consents' => TestAccounts::consents(['art9' => ['accepted' => false], 'privacy' => ['version' => 'test-privacy-v0']]),
            'isAdmin' => true,
        ]);

        self::assertSame(
            [
                ['field' => 'isAdmin', 'code' => 'unknown_field'],
                ['field' => 'email', 'code' => 'invalid_format'],
                ['field' => 'displayName', 'code' => 'too_short'],
                ['field' => 'avatar', 'code' => 'invalid_value'],
                ['field' => 'locale', 'code' => 'invalid_value'],
                ['field' => 'timeZone', 'code' => 'invalid_value'],
                ['field' => 'password', 'code' => 'too_short'],
                ['field' => 'consents.art9.accepted', 'code' => 'not_accepted'],
                ['field' => 'consents.privacy.version', 'code' => 'outdated_version'],
            ],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $response)),
        );
        $this->assertNothingStoredOrSent();
    }

    public function test_FR_ACC_01_an_empty_request_names_every_required_field(): void
    {
        $response = $this->callInvalid('POST', '/auth/register');

        self::assertSame(
            ['email', 'password', 'displayName', 'avatar', 'locale', 'timeZone', 'consents'],
            array_column((array) (self::assertProblem(422, 'validation_failed', $response)['errors'] ?? []), 'field'),
        );
    }

    /**
     * @return iterable<string, array{string, string|null}> The password, and the code it is rejected with (null when it is accepted).
     */
    public static function passwordLengths(): iterable
    {
        yield '9 characters' => [str_repeat('a', 9), 'too_short'];
        yield '10 characters' => [str_repeat('a', 10), null];
        yield '200 characters' => [str_repeat('ä', 200), null];
        yield '201 characters' => [str_repeat('a', 201), 'too_long'];
    }

    #[DataProvider('passwordLengths')]
    public function test_FR_ACC_01_a_password_has_10_to_200_characters(string $password, ?string $rejectedAs): void
    {
        $accepted = $rejectedAs === null;
        $body = TestAccounts::registration(['password' => $password]);

        if ($accepted) {
            self::assertSame(202, $this->call('POST', '/auth/register', $body)->status());
            self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM users'));

            return;
        }

        self::assertSame(
            [['field' => 'password', 'code' => $rejectedAs]],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/register', $body))),
        );
        $this->assertNothingStoredOrSent();
    }

    public function test_NFR_05_a_password_is_never_trimmed_and_an_email_always_is(): void
    {
        $password = '  spaces are part of it  ';
        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => '  Anna@Example.ORG ', 'password' => $password]));

        self::assertSame('anna@example.org', $this->sql()->value('SELECT email FROM users'));
        $hash = $this->sql()->value('SELECT password_hash FROM users');
        self::assertIsString($hash);
        self::assertTrue(password_verify($password, $hash));
        self::assertFalse(password_verify(trim($password), $hash));
    }

    public function test_FR_ACC_01_a_new_email_is_mailed_a_confirmation_link_in_its_language(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration(['locale' => 'en']));

        $mail = $this->mailTo('anna@example.org');
        self::assertSame('confirm_email', $mail->template);
        self::assertSame('Almost there: confirm your email address', $mail->subject);
        $token = self::tokenIn($mail);
        // 32 random bytes as base64url.
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
        self::assertStringContainsString("https://app.example.test/verify-email?token={$token}\n", $mail->text);
        self::assertStringContainsString("https://app.example.test/verify-email?token={$token}\"", $mail->html);
        self::assertCount(1, $this->mails()->sent());

        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'bernd@example.org', 'locale' => 'de']));
        self::assertSame('Fast geschafft: Bestätige deine E-Mail-Adresse', $this->mailTo('bernd@example.org')->subject);
    }

    public function test_NFR_05_the_confirmation_token_is_stored_as_a_sha256_hash_and_valid_24_hours(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $token = self::tokenIn($this->mailTo('anna@example.org'));

        self::assertSame(
            [['purpose' => 'verify_email', 'token_hash' => hash('sha256', $token), 'expires_at' => '2026-11-18 12:00:00', 'used_at' => null]],
            $this->sql()->rows('SELECT purpose, token_hash, expires_at, used_at FROM one_time_tokens'),
        );
        self::assertSame(TokenHash::of($token), hash('sha256', $token));
        // The token is in the mail and nowhere else.
        self::assertStringNotContainsString($token, $this->everythingStoredAndLogged());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesOfAnEmail(): iterable
    {
        yield 'confirmed account' => ['confirmed'];
        yield 'unconfirmed registration' => ['unconfirmed'];
    }

    #[DataProvider('statesOfAnEmail')]
    public function test_FR_ACC_01_registration_answers_identically_whether_or_not_the_email_has_an_account(string $state): void
    {
        $forNewEmail = $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'new@example.org']));
        $this->givenUser('anna@example.org', verified: $state === 'confirmed');
        $this->mails()->clear();

        $forExistingEmail = $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'anna@example.org']));

        // Status, every header and the body: nothing tells the two apart.
        self::assertSame(self::everythingAClientSees($forNewEmail), self::everythingAClientSees($forExistingEmail));
        self::assertSame(202, $forExistingEmail->status());
        // Only the mail differs, and in both cases there is exactly one.
        self::assertSame($state === 'confirmed' ? 'already_registered' : 'confirm_email', $this->mailTo('anna@example.org')->template);
        self::assertCount(1, $this->mails()->sent());
    }

    public function test_FR_ACC_01_a_confirmed_account_is_never_modified_by_a_registration_attempt(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $this->logIn();
        $before = $this->accountData();

        $this->call('POST', '/auth/register', TestAccounts::registration([
            'email' => 'ANNA@example.org',
            'password' => 'the password of an attacker',
            'displayName' => 'Mallory',
            'avatar' => 'avatar-12',
            'locale' => 'en',
            'timeZone' => 'Pacific/Auckland',
        ]));

        self::assertSame($before, $this->accountData());
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM users'));
        // The owner's password still works and the attacker's does not.
        $this->logIn('anna@example.org', TestAccounts::PASSWORD);
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', [
            'email' => 'anna@example.org', 'password' => 'the password of an attacker', 'refreshIn' => 'body',
        ]));
        self::assertNotNull($anna->emailVerifiedAt);
    }

    public function test_FR_ACC_01_the_owner_of_a_confirmed_account_is_told_that_somebody_tried_without_any_token(): void
    {
        $this->givenUser('anna@example.org', locale: Locale::En);

        $this->call('POST', '/auth/register', TestAccounts::registration(['locale' => 'de']));

        $mail = $this->mailTo('anna@example.org');
        self::assertSame('already_registered', $mail->template);
        // In the owner's language, not in the one of the form.
        self::assertSame('You already have a Smoke Detector account', $mail->subject);
        self::assertStringContainsString("https://app.example.test/login\n", $mail->text);
        self::assertStringContainsString("https://app.example.test/reset-password\n", $mail->text);
        self::assertStringNotContainsString('token=', $mail->text . $mail->html);
        self::assertSame([], $this->sql()->rows('SELECT * FROM one_time_tokens'));
        // Nothing of what the stranger typed is in the mail.
        self::assertStringNotContainsString('Anna', $mail->text);
    }

    public function test_FR_ACC_01_registering_an_unconfirmed_email_again_replaces_the_pending_data_and_invalidates_the_older_link(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration(['password' => 'the first password', 'displayName' => 'First']));
        $firstToken = self::tokenIn($this->mailTo('anna@example.org'));
        $firstId = $this->sql()->value('SELECT id FROM users');
        $this->mails()->clear();
        $this->clock()->advanceSeconds(3600);

        $this->call('POST', '/auth/register', TestAccounts::registration([
            'email' => 'Anna@example.org', 'password' => 'the second password', 'displayName' => 'Second', 'avatar' => 'avatar-05', 'locale' => 'en', 'timeZone' => 'Europe/Vienna',
        ]));
        $secondToken = self::tokenIn($this->mailTo('anna@example.org'));

        self::assertSame(
            [[
                'id' => $firstId,
                'email' => 'anna@example.org',
                'display_name' => 'Second',
                'avatar_key' => 'avatar-05',
                'locale' => 'en',
                'timezone' => 'Europe/Vienna',
                'email_verified_at' => null,
                'created_at' => '2026-11-17 13:00:00',
            ]],
            $this->sql()->rows('SELECT id, email, display_name, avatar_key, locale, timezone, email_verified_at, created_at FROM users'),
        );
        self::assertSame(
            ['2026-11-17 13:00:00', '2026-11-17 13:00:00', '2026-11-17 13:00:00'],
            $this->sql()->column('SELECT granted_at FROM consents'),
        );

        // The older link is dead, the new one works, and the password is the second one.
        self::assertNotSame($firstToken, $secondToken);
        self::assertProblem(400, 'invalid_token', $this->call('POST', '/auth/verify-email', self::confirmation($firstToken)));
        self::assertNull($this->sql()->value('SELECT email_verified_at FROM users'));
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/verify-email', self::confirmation($secondToken, 'the first password')));
        self::assertNull($this->sql()->value('SELECT email_verified_at FROM users'));
        $this->confirmEmail($secondToken, 'the second password');
        $this->logIn('anna@example.org', 'the second password');
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', [
            'email' => 'anna@example.org', 'password' => 'the first password', 'refreshIn' => 'body',
        ]));
    }

    public function test_FR_ACC_03_the_link_with_the_password_of_the_sign_up_confirms_the_account_and_logs_in(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $token = self::tokenIn($this->mailTo('anna@example.org'));
        $this->clock()->advanceSeconds(120);

        $response = $this->call('POST', '/auth/verify-email', [
            'token' => $token, 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body', 'deviceLabel' => 'Annas iPhone',
        ]);

        self::assertSame(200, $response->status());
        self::assertSame('2026-11-17 12:02:00', $this->sql()->value('SELECT email_verified_at FROM users'));
        self::assertSame('2026-11-17 12:02:00', $this->sql()->value('SELECT used_at FROM one_time_tokens'));
        // The answer of a login: the same members, the same kind of tokens, one session stored.
        $session = $response->json();
        self::assertSame(['accessToken', 'tokenType', 'expiresIn', 'refreshToken'], array_keys($session));
        self::assertSame('Bearer', $session['tokenType']);
        self::assertSame(900, $session['expiresIn']);
        self::assertIsString($session['refreshToken']);
        self::assertIsString($session['accessToken']);
        self::assertFalse($response->response->hasHeader('Set-Cookie'));
        self::assertSame(
            [[
                'user_id' => $this->sql()->value('SELECT id FROM users'),
                'token_hash' => hash('sha256', $session['refreshToken']),
                'device_label' => 'Annas iPhone',
                'created_at' => '2026-11-17 12:02:00',
                'expires_at' => '2027-01-16 12:02:00',
                'revoked_at' => null,
            ]],
            $this->sql()->rows('SELECT user_id, token_hash, device_label, created_at, expires_at, revoked_at FROM refresh_tokens'),
        );
        // The user is logged in: both tokens work.
        $me = $this->call('GET', '/me', headers: ['Authorization' => 'Bearer ' . $session['accessToken']]);
        self::assertSame('anna@example.org', $me->json()['email'] ?? null);
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $session['refreshToken']])->status());
        // And a login with the password works as well.
        $this->logIn();
    }

    public function test_NFR_06_confirming_in_cookie_mode_delivers_the_refresh_token_in_the_cookie_only(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $token = self::tokenIn($this->mailTo('anna@example.org'));
        $this->givenUser('bob@example.org');

        $confirmed = $this->confirmEmail($token, refreshIn: 'cookie');
        $loggedIn = $this->logIn('bob@example.org', refreshIn: 'cookie');

        self::assertSame(['accessToken', 'tokenType', 'expiresIn'], array_keys($confirmed->json()));
        $cookies = $confirmed->response->getHeader('Set-Cookie');
        self::assertCount(1, $cookies);
        self::assertSame(
            1,
            preg_match('~\Arefresh_token=([A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}); Max-Age=5184000; Path=/api/v1/auth; Secure; HttpOnly; SameSite=Strict\z~', $cookies[0], $match),
            $cookies[0],
        );
        self::assertStringNotContainsString($match[1] ?? '', $confirmed->body());
        self::assertSame(
            hash('sha256', $match[1] ?? ''),
            $this->sql()->value('SELECT token_hash FROM refresh_tokens r JOIN users u ON u.id = r.user_id WHERE u.email = ?', ['anna@example.org']),
        );
        // Header for header what a login answers with (the tokens themselves aside).
        self::assertSame(array_keys($loggedIn->response->getHeaders()), array_keys($confirmed->response->getHeaders()));
    }

    public function test_FR_ACC_03_a_valid_link_with_a_wrong_password_confirms_nothing_and_gives_nobody_a_session(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration(['displayName' => 'Annabella', 'timeZone' => 'Europe/Vienna']));
        $token = self::tokenIn($this->mailTo('anna@example.org'));
        $this->mails()->clear();
        $before = $this->sql()->rows('SELECT * FROM users');

        $response = $this->call('POST', '/auth/verify-email', self::confirmation($token, 'not the password', 'cookie'));

        // The type of a failed login. The body says nothing about the registration the link belongs
        // to: no address, no name, nothing of what was typed at sign-up.
        self::assertSame(
            ['type' => 'invalid_credentials', 'title' => 'Invalid credentials', 'status' => 401, 'detail' => 'The password is not the one of this sign-up.'],
            self::assertProblem(401, 'invalid_credentials', $response),
        );
        foreach (['anna', 'example.org', 'Annabella', 'avatar', 'Vienna', $token] as $ofTheRegistration) {
            self::assertStringNotContainsStringIgnoringCase($ofTheRegistration, $response->body() . json_encode($response->response->getHeaders(), JSON_THROW_ON_ERROR));
        }
        self::assertFalse($response->response->hasHeader('Set-Cookie'));
        self::assertSame($before, $this->sql()->rows('SELECT * FROM users'));
        self::assertSame([], $this->sql()->rows('SELECT * FROM refresh_tokens'));
        self::assertSame([], $this->mails()->sent());
        // The link stays usable, with one of its five tries gone.
        self::assertSame([['used_at' => null, 'failed_attempts' => 1]], $this->sql()->rows('SELECT used_at, failed_attempts FROM one_time_tokens'));
        $this->confirmEmail($token);
        self::assertNotNull($this->sql()->value('SELECT email_verified_at FROM users'));
    }

    public function test_NFR_05_the_fifth_wrong_password_deletes_the_link(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $token = self::tokenIn($this->mailTo('anna@example.org'));
        $this->mails()->clear();

        for ($i = 1; $i <= 5; $i++) {
            // From a new client each time: the five tries are the link's, whoever makes them.
            $this->fromClientAddress("198.51.100.{$i}");
            self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/verify-email', self::confirmation($token, "guess number {$i}")));
        }
        $this->fromClientAddress('198.51.100.6');
        $sixth = $this->call('POST', '/auth/verify-email', self::confirmation($token));
        $unknown = $this->call('POST', '/auth/verify-email', self::confirmation('q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng'));

        // Gone, and answered like a link that never existed, also with the right password.
        self::assertProblem(400, 'invalid_token', $sixth);
        self::assertSame(self::everythingAClientSees($unknown), self::everythingAClientSees($sixth));
        self::assertSame([], $this->sql()->rows('SELECT * FROM one_time_tokens'));
        self::assertNull($this->sql()->value('SELECT email_verified_at FROM users'));
        self::assertSame([], $this->sql()->rows('SELECT * FROM refresh_tokens'));
        // The registration is still there: a new link is one request away, with five new tries.
        $this->call('POST', '/auth/resend-verification', ['email' => 'anna@example.org']);
        self::assertSame(0, $this->sql()->value('SELECT failed_attempts FROM one_time_tokens'));
        $this->confirmEmail(self::tokenIn($this->mailTo('anna@example.org')));
    }

    public function test_FR_ACC_01_a_stranger_who_registers_the_address_last_cannot_take_the_account_over(): void
    {
        // The attack of the step 3 security review (SRS OQ-10). The attacker registers the address,
        // the owner registers, the attacker registers again: the newest link, which lands in the
        // owner's mailbox, now belongs to the attacker's submission and its password.
        $attacker = TestAccounts::registration(['password' => 'the attackers password', 'displayName' => 'Mallory']);
        $owner = TestAccounts::registration(['password' => 'the owners password']);
        $this->fromClientAddress('198.51.100.66');
        $this->call('POST', '/auth/register', $attacker);
        $this->fromClientAddress(self::CLIENT_ADDRESS);
        $this->call('POST', '/auth/register', $owner);
        $this->fromClientAddress('198.51.100.66');
        $this->call('POST', '/auth/register', $attacker);
        $newestLink = self::tokenIn($this->mails()->sent()[2]);
        $this->mails()->clear();

        // The owner clicks the newest link and types the password they chose.
        $this->fromClientAddress(self::CLIENT_ADDRESS);
        $refused = $this->call('POST', '/auth/verify-email', self::confirmation($newestLink, 'the owners password'));

        // No account is activated, and the attacker's password logs nobody in.
        self::assertProblem(401, 'invalid_credentials', $refused);
        self::assertNull($this->sql()->value('SELECT email_verified_at FROM users'));
        self::assertSame([], $this->sql()->rows('SELECT * FROM refresh_tokens'));
        $this->fromClientAddress('198.51.100.66');
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', [
            'email' => 'anna@example.org', 'password' => 'the attackers password', 'refreshIn' => 'body',
        ]));
        self::assertSame([], $this->sql()->rows('SELECT * FROM refresh_tokens'));
        $this->mails()->clear();

        // The owner signs up again and confirms with their own password: confirmed and logged in.
        // (In the same hour: what the attacker's client asked for is not held against the owner's.)
        $this->fromClientAddress(self::CLIENT_ADDRESS);
        self::assertSame(202, $this->call('POST', '/auth/register', $owner)->status());
        $confirmed = $this->call('POST', '/auth/verify-email', self::confirmation(self::tokenIn($this->mailTo('anna@example.org')), 'the owners password'));

        self::assertSame(200, $confirmed->status());
        $accessToken = $confirmed->json()['accessToken'] ?? null;
        self::assertIsString($accessToken);
        self::assertIsString($confirmed->json()['refreshToken'] ?? null);
        self::assertSame(
            ['email' => 'anna@example.org', 'displayName' => 'Anna'],
            array_intersect_key($this->call('GET', '/me', headers: ['Authorization' => 'Bearer ' . $accessToken])->json(), ['email' => 0, 'displayName' => 0]),
        );
        // The account is the owner's alone: the attacker's password is refused.
        $this->fromClientAddress('198.51.100.67');
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', [
            'email' => 'anna@example.org', 'password' => 'the attackers password', 'refreshIn' => 'body',
        ]));
        $this->logIn('anna@example.org', 'the owners password');
    }

    public function test_NFR_05_a_wrong_password_on_the_confirmation_page_counts_as_a_failed_login_of_the_client(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $token = self::tokenIn($this->mailTo('anna@example.org'));
        $this->sql()->execute('DELETE FROM rate_limits');

        $this->call('POST', '/auth/verify-email', self::confirmation($token, 'not the password'));

        self::assertSame(
            [['key' => RateLimitKey::of('login.client', self::CLIENT_ADDRESS), 'window_start' => '2026-11-17 12:00:00', 'hits' => 1]],
            $this->sql()->rows('SELECT * FROM rate_limits'),
        );
        // A token that is worth nothing is no password check, and the right password is no failure.
        $this->call('POST', '/auth/verify-email', self::confirmation('q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng'));
        $this->confirmEmail($token);
        self::assertSame([1], $this->sql()->column('SELECT hits FROM rate_limits'));
    }

    public function test_NFR_05_a_client_with_too_many_failed_logins_cannot_try_passwords_on_a_link(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $token = self::tokenIn($this->mailTo('anna@example.org'));
        $limit = AuthLimits::loginFailuresPerClient();
        $this->sql()->execute(
            'INSERT INTO rate_limits (`key`, window_start, hits) VALUES (?, ?, ?)',
            [RateLimitKey::of($limit->name, self::CLIENT_ADDRESS), '2026-11-17 12:00:00', $limit->maxHits],
        );

        $refused = $this->call('POST', '/auth/verify-email', self::confirmation($token));

        self::assertProblem(429, 'rate_limited', $refused);
        self::assertSame('900', $refused->header('Retry-After'));
        self::assertNull($this->sql()->value('SELECT email_verified_at FROM users'));
        // The refused request used up none of the link's tries, and another client confirms with it.
        self::assertSame(0, $this->sql()->value('SELECT failed_attempts FROM one_time_tokens'));
        $this->fromClientAddress('198.51.100.1');
        $this->confirmEmail($token);
    }

    public function test_FR_ACC_03_a_link_that_is_sent_again_late_works_for_its_24_hours_and_the_cleanup_leaves_it_alone(): void
    {
        // Registered at 12:00, asked again at 11:00 the next day, clicked at 12:30: the mail of
        // 11:00 says "valid for 24 hours", so neither the link nor the registration may be gone.
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $this->mails()->clear();
        $this->clock()->advanceSeconds(23 * 3600);
        $this->call('POST', '/auth/resend-verification', ['email' => 'anna@example.org']);
        $this->clock()->advanceSeconds(5400);

        self::assertSame(0, $this->service(PurgeExpiredData::class)->execute()->unconfirmedRegistrations);
        $response = $this->call('POST', '/auth/verify-email', self::confirmation(self::tokenIn($this->mailTo('anna@example.org'))));

        self::assertSame(200, $response->status());
        $this->logIn();
    }

    public function test_FR_ACC_03_a_registration_older_than_24_hours_cannot_be_revived_even_when_the_cleanup_has_not_run(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $link = self::tokenIn($this->mailTo('anna@example.org'));
        $this->mails()->clear();
        $this->clock()->advanceSeconds(86_400);
        $before = $this->sql()->rows('SELECT * FROM users');

        $resend = $this->call('POST', '/auth/resend-verification', ['email' => 'anna@example.org']);
        $login = $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body']);
        $verify = $this->call('POST', '/auth/verify-email', self::confirmation($link));

        // Answered like any other address, and nothing happened: no mail, no new link, no account.
        self::assertSame(202, $resend->status());
        self::assertProblem(401, 'invalid_credentials', $login);
        self::assertProblem(400, 'invalid_token', $verify);
        self::assertSame([], $this->mails()->sent());
        self::assertSame($before, $this->sql()->rows('SELECT * FROM users'));
        // Registering again is what brings it back, as a new registration with a new link.
        $this->call('POST', '/auth/register', TestAccounts::registration());
        self::assertSame(200, $this->call('POST', '/auth/verify-email', self::confirmation(self::tokenIn($this->mailTo('anna@example.org'))))->status());
    }

    public function test_FR_ACC_03_login_is_refused_until_the_email_is_confirmed(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $login = ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body'];

        $refused = $this->call('POST', '/auth/login', $login);

        // Refused like a wrong password: an answer of its own would tell who is registered.
        self::assertProblem(401, 'invalid_credentials', $refused);
        self::assertArrayNotHasKey('accessToken', $refused->json());
        self::assertSame([], $this->sql()->rows('SELECT * FROM refresh_tokens'));

        // The login with the right password has mailed a second link, and that is the one that works now.
        $mails = $this->mails()->sent();
        self::assertCount(2, $mails);
        $this->call('POST', '/auth/verify-email', self::confirmation(self::tokenIn($mails[1])));
        self::assertSame(200, $this->call('POST', '/auth/login', $login)->status());
    }

    public function test_FR_ACC_03_a_wrong_an_expired_and_a_used_token_give_the_same_error(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'used@example.org']));
        $used = self::tokenIn($this->mailTo('used@example.org'));
        $this->call('POST', '/auth/verify-email', self::confirmation($used));
        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'expired@example.org']));
        $expired = self::tokenIn($this->mailTo('expired@example.org'));
        $this->clock()->advanceSeconds(86_400);

        $responses = [
            'used' => $this->call('POST', '/auth/verify-email', self::confirmation($used)),
            'expired' => $this->call('POST', '/auth/verify-email', self::confirmation($expired)),
            'wrong' => $this->call('POST', '/auth/verify-email', self::confirmation('q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng')),
            'not even a token' => $this->call('POST', '/auth/verify-email', self::confirmation("' OR 1=1 -- ")),
        ];

        foreach ($responses as $response) {
            self::assertProblem(400, 'invalid_token', $response);
            self::assertSame(self::everythingAClientSees($responses['wrong']), self::everythingAClientSees($response));
        }
        self::assertSame(
            ['type' => 'invalid_token', 'title' => 'Invalid token', 'status' => 400, 'detail' => 'The token is unknown, expired or already used.'],
            $responses['wrong']->json(),
        );
        self::assertNull($this->sql()->value('SELECT email_verified_at FROM users WHERE email = ?', ['expired@example.org']));
    }

    public function test_FR_ACC_03_a_link_works_until_just_before_24_hours_are_over_and_only_once(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $token = self::tokenIn($this->mailTo('anna@example.org'));
        $this->clock()->advanceSeconds(86_399);

        self::assertSame(200, $this->call('POST', '/auth/verify-email', self::confirmation($token))->status());
        self::assertProblem(400, 'invalid_token', $this->call('POST', '/auth/verify-email', self::confirmation($token)));
    }

    public function test_FR_ACC_03_verify_email_needs_a_token_a_password_and_where_the_refresh_token_goes(): void
    {
        $valid = self::confirmation('abc');
        $invalid = [
            null,
            [],
            ['token' => 'abc'],
            array_diff_key($valid, ['password' => 0]),
            array_diff_key($valid, ['refreshIn' => 0]),
            ['token' => ''] + $valid,
            ['token' => 42] + $valid,
            ['token' => str_repeat('a', 201)] + $valid,
            ['password' => ''] + $valid,
            ['password' => str_repeat('a', 201)] + $valid,
            ['password' => 12345678901] + $valid,
            ['refreshIn' => 'header'] + $valid,
            ['deviceLabel' => ''] + $valid,
            $valid + ['email' => 'anna@example.org'],
        ];
        foreach ($invalid as $body) {
            self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/verify-email', $body));
        }

        self::assertSame(
            [['field' => 'token', 'code' => 'required'], ['field' => 'password', 'code' => 'required'], ['field' => 'refreshIn', 'code' => 'required']],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/verify-email', []))),
        );
        self::assertSame(
            [['field' => 'refreshIn', 'code' => 'invalid_value']],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/verify-email', ['refreshIn' => 'header'] + $valid))),
        );
    }

    public function test_FR_ACC_03_the_password_on_the_confirmation_page_is_compared_exactly_as_sent(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration(['password' => ' spaces are part of it ']));
        $token = self::tokenIn($this->mailTo('anna@example.org'));

        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/verify-email', self::confirmation($token, 'spaces are part of it')));
        $this->confirmEmail($token, ' spaces are part of it ');
    }

    public function test_FR_ACC_03_the_link_can_be_sent_again_and_the_older_one_stops_working(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration(['locale' => 'en']));
        $first = self::tokenIn($this->mailTo('anna@example.org'));
        $this->mails()->clear();

        $response = $this->call('POST', '/auth/resend-verification', ['email' => ' ANNA@example.org ']);

        self::assertSame(202, $response->status());
        self::assertSame(['status' => 'verification_sent'], $response->json());
        $mail = $this->mailTo('anna@example.org');
        self::assertSame('Almost there: confirm your email address', $mail->subject);
        $second = self::tokenIn($mail);
        self::assertProblem(400, 'invalid_token', $this->call('POST', '/auth/verify-email', self::confirmation($first)));
        self::assertSame(200, $this->call('POST', '/auth/verify-email', self::confirmation($second))->status());
    }

    public function test_FR_ACC_03_resending_answers_identically_for_unknown_confirmed_and_unconfirmed_emails(): void
    {
        $this->givenUser('confirmed@example.org');
        $this->givenUser('unconfirmed@example.org', verified: false);

        $unknown = $this->call('POST', '/auth/resend-verification', ['email' => 'nobody@example.org']);
        $confirmed = $this->call('POST', '/auth/resend-verification', ['email' => 'confirmed@example.org']);
        $unconfirmed = $this->call('POST', '/auth/resend-verification', ['email' => 'unconfirmed@example.org']);

        self::assertSame(202, $unknown->status());
        self::assertSame(self::everythingAClientSees($unknown), self::everythingAClientSees($confirmed));
        self::assertSame(self::everythingAClientSees($unknown), self::everythingAClientSees($unconfirmed));
        self::assertSame('confirm_email', $this->mailTo('unconfirmed@example.org')->template);
        self::assertSame('already_registered', $this->mailTo('confirmed@example.org')->template);
        self::assertCount(2, $this->mails()->sent());
    }

    public function test_NFR_05_the_sixth_registration_from_one_client_address_within_an_hour_is_429_with_retry_after(): void
    {
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-17 12:10:00 UTC'));
        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(202, $this->call('POST', '/auth/register', TestAccounts::registration(['email' => "user{$i}@example.org"]))->status());
        }

        $response = $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'user6@example.org']));

        $problem = self::assertProblem(429, 'rate_limited', $response);
        // The window is the clock hour: 50 minutes are left.
        self::assertSame('3000', $response->header('Retry-After'));
        self::assertSame('Try again in 3000 seconds.', $problem['detail'] ?? null);
        self::assertSame(5, $this->sql()->value('SELECT COUNT(*) FROM users'));
        self::assertCount(5, $this->mails()->sent());

        // Another client address is not affected, and neither is this one in the next hour.
        $this->fromClientAddress('198.51.100.20');
        self::assertSame(202, $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'user6@example.org']))->status());
        $this->fromClientAddress(self::CLIENT_ADDRESS);
        $this->clock()->advanceSeconds(3000);
        self::assertSame(202, $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'user7@example.org']))->status());
    }

    public function test_NFR_05_the_fourth_request_of_one_client_for_one_email_within_an_hour_is_429_whatever_the_endpoint(): void
    {
        $this->givenUser('anna@example.org', verified: false);
        $stranger = '198.51.100.66';

        $this->fromClientAddress($stranger);
        self::assertSame(202, $this->call('POST', '/auth/register', TestAccounts::registration())->status());
        self::assertSame(202, $this->call('POST', '/auth/resend-verification', ['email' => 'anna@example.org'])->status());
        self::assertSame(202, $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org'])->status());
        $mails = count($this->mails()->sent());
        // (So that it is the limit of the pair that answers below, and not the client's own five an hour.)
        $this->sql()->execute('DELETE FROM rate_limits WHERE `key` = ?', [RateLimitKey::of('mail.client', $stranger)]);

        foreach ([
            ['/auth/register', TestAccounts::registration(['email' => 'Anna@Example.org'])],
            ['/auth/resend-verification', ['email' => 'anna@example.org']],
            ['/auth/password-reset/request', ['email' => 'anna@example.org']],
        ] as [$path, $body]) {
            $response = $this->call('POST', $path, $body);

            self::assertProblem(429, 'rate_limited', $response);
            self::assertSame('3600', $response->header('Retry-After'));
        }
        self::assertCount($mails, $this->mails()->sent());

        // The limit is the pair's: this client can still ask for a mail to another address …
        self::assertSame(202, $this->call('POST', '/auth/resend-verification', ['email' => 'bob@example.org'])->status());
        // … and the address is not locked for anybody else (SRS OQ-11): its owner gets a new link.
        $this->fromClientAddress(self::CLIENT_ADDRESS);
        self::assertSame(202, $this->call('POST', '/auth/resend-verification', ['email' => 'anna@example.org'])->status());
        self::assertCount($mails + 1, $this->mails()->sent());

        // The limit is the same for an address without an account: being limited tells nothing.
        $this->fromClientAddress('198.51.100.67');
        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(202, $this->call('POST', '/auth/resend-verification', ['email' => 'nobody@example.org'])->status());
        }
        self::assertProblem(429, 'rate_limited', $this->call('POST', '/auth/resend-verification', ['email' => 'nobody@example.org']));
    }

    public function test_NFR_05_the_eleventh_mail_to_an_address_within_an_hour_is_left_out_and_the_answer_is_the_usual_202(): void
    {
        // Ten mails an hour to one address, whoever asks: above that nothing is sent, and nothing is
        // refused either. A 429 would keep the owner from registering because of what others did.
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $link = self::tokenIn($this->mailTo('anna@example.org'));
        $this->mails()->clear();
        $usual = [
            'register' => $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'carla@example.org'])),
            'resend' => $this->call('POST', '/auth/resend-verification', ['email' => 'carla@example.org']),
            'reset' => $this->call('POST', '/auth/password-reset/request', ['email' => 'carla@example.org']),
        ];
        $this->mails()->clear();
        // Ten requests for a mail to each of the two addresses are on the counters, from all over the net.
        foreach (['anna@example.org', 'nobody@example.org'] as $address) {
            $this->sql()->execute(
                'INSERT INTO rate_limits (`key`, window_start, hits) VALUES (?, ?, 10) ON DUPLICATE KEY UPDATE hits = 10',
                [RateLimitKey::of('mail.address', $address), '2026-11-17 12:00:00'],
            );
        }
        $before = [$this->sql()->rows('SELECT * FROM users'), $this->sql()->rows('SELECT * FROM one_time_tokens'), $this->sql()->rows('SELECT * FROM consents')];

        $answers = [];
        foreach (['anna@example.org' => '198.51.100.1', 'nobody@example.org' => '198.51.100.2'] as $address => $client) {
            $this->fromClientAddress($client);
            $answers[$address] = [
                'register' => $this->call('POST', '/auth/register', TestAccounts::registration(['email' => $address, 'password' => 'another password', 'displayName' => 'Somebody else'])),
                'resend' => $this->call('POST', '/auth/resend-verification', ['email' => $address]),
                'reset' => $this->call('POST', '/auth/password-reset/request', ['email' => $address]),
            ];
        }

        // Answered exactly like the requests that did send a mail, for the address that is
        // registered and for the one nobody has.
        foreach ($answers as $ofAddress) {
            foreach ($ofAddress as $endpoint => $response) {
                self::assertSame(202, $response->status());
                self::assertSame(self::everythingAClientSees($usual[$endpoint]), self::everythingAClientSees($response), $endpoint);
            }
        }
        // No mail, and nothing changed: the registration that is waiting keeps its data and its
        // link, which is the one of the last mail that did go out.
        self::assertSame([], $this->mails()->sent());
        self::assertSame($before, [$this->sql()->rows('SELECT * FROM users'), $this->sql()->rows('SELECT * FROM one_time_tokens'), $this->sql()->rows('SELECT * FROM consents')]);
        $this->confirmEmail($link);

        // In the next hour the address is mailed again.
        $this->clock()->advanceSeconds(3600);
        $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);
        self::assertSame('reset_password', $this->mailTo('anna@example.org')->template);
    }

    public function test_NFR_05_the_client_address_of_the_rate_limit_is_the_connection_and_never_a_header(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->call('POST', '/auth/resend-verification', ['email' => "nobody{$i}@example.org"], ['X-Forwarded-For' => "198.51.100.{$i}"]);
        }

        // A new "address" in a header a client writes itself buys nothing.
        $response = $this->call('POST', '/auth/resend-verification', ['email' => 'nobody6@example.org'], [
            'X-Forwarded-For' => '198.51.100.99', 'X-Real-IP' => '198.51.100.99', 'Forwarded' => 'for=198.51.100.99',
        ]);

        self::assertProblem(429, 'rate_limited', $response);
    }

    public function test_NFR_05_rate_limit_counters_hold_no_email_and_no_client_address(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration());

        $keys = $this->sql()->column('SELECT `key` FROM rate_limits ORDER BY `key`');
        self::assertSame(
            [
                RateLimitKey::of('mail.address', 'anna@example.org'),
                RateLimitKey::of('mail.client', self::CLIENT_ADDRESS),
                // The address as asked for from this client: two hashes, the one of the address last.
                RateLimitKey::of('mail.pair', 'anna@example.org', self::CLIENT_ADDRESS),
            ],
            $keys,
        );
        self::assertStringNotContainsString('anna', implode(' ', array_map(strval(...), $keys)));
        self::assertStringNotContainsString(self::CLIENT_ADDRESS, implode(' ', array_map(strval(...), $keys)));
    }

    public function test_a_registration_that_is_not_json_or_too_large_is_refused_before_anything_else(): void
    {
        self::assertProblem(400, 'malformed_json', $this->callInvalid('POST', '/auth/register', '{"email": '));
        self::assertProblem(415, 'unsupported_media_type', $this->callInvalid('POST', '/auth/register', 'email=anna@example.org', ['Content-Type' => 'application/x-www-form-urlencoded']));
        self::assertProblem(413, 'payload_too_large', $this->callInvalid('POST', '/auth/register', TestAccounts::registration(['displayName' => str_repeat('a', 70_000)])));
        $this->assertNothingStoredOrSent();
    }

    /**
     * Status, body and every header of a response.
     *
     * @return array{int, string, array<array-key, mixed>}
     */
    private static function everythingAClientSees(ApiResponse $response): array
    {
        return [$response->status(), $response->body(), $response->response->getHeaders()];
    }

    /**
     * Every row that belongs to accounts, as it is stored.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function accountData(): array
    {
        return [
            'users' => $this->sql()->rows('SELECT * FROM users ORDER BY id'),
            'consents' => $this->sql()->rows('SELECT * FROM consents ORDER BY user_id, type'),
            'one_time_tokens' => $this->sql()->rows('SELECT * FROM one_time_tokens ORDER BY id'),
            'refresh_tokens' => $this->sql()->rows('SELECT * FROM refresh_tokens ORDER BY id'),
        ];
    }

    private function assertNothingStoredOrSent(): void
    {
        self::assertSame(
            ['users' => [], 'consents' => [], 'one_time_tokens' => [], 'refresh_tokens' => []],
            $this->accountData(),
        );
        self::assertSame([], $this->sql()->rows('SELECT * FROM rate_limits'));
        self::assertSame([], $this->mails()->sent());
    }

    /**
     * Every row of every table and everything that was logged, as one text to search in.
     */
    private function everythingStoredAndLogged(): string
    {
        $text = $this->logs()->text();
        foreach (['users', 'consents', 'one_time_tokens', 'refresh_tokens', 'rate_limits'] as $table) {
            $text .= json_encode($this->sql()->rows("SELECT * FROM {$table}"), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return $text;
    }
}
