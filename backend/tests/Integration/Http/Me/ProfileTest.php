<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Me;

use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * GET /me and PATCH /me through the real application and database (HLD §4.5 "Account", FR-ACC-09).
 */
final class ProfileTest extends HttpTestCase
{
    public function test_FR_ACC_09_me_is_the_profile_of_the_user_the_token_belongs_to(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $bob = $this->givenUser('bob@example.org', locale: Locale::En);

        $response = $this->call('GET', '/me', headers: $this->authorization($anna));

        self::assertSame(200, $response->status());
        self::assertSame(
            [
                'id' => $anna->id->value,
                'email' => 'anna@example.org',
                'displayName' => 'Test User',
                'avatar' => 'avatar-01',
                'locale' => 'de',
                'timeZone' => 'Europe/Berlin',
                'onboardingComplete' => false,
            ],
            $response->json(),
        );
        $bobs = $this->call('GET', '/me', headers: $this->authorization($bob))->json();
        self::assertSame([$bob->id->value, 'bob@example.org', 'en'], [$bobs['id'] ?? null, $bobs['email'] ?? null, $bobs['locale'] ?? null]);
    }

    public function test_NFR_05_me_never_carries_the_password_hash(): void
    {
        $anna = $this->givenUser();

        $body = $this->call('GET', '/me', headers: $this->authorization($anna))->body();

        self::assertStringNotContainsString('argon2', $body);
        self::assertStringNotContainsString(substr(TestAccounts::PASSWORD_HASH, -20), $body);
        self::assertStringNotContainsStringIgnoringCase('password', $body);
    }

    public function test_FR_ACC_09_display_name_avatar_language_and_time_zone_can_be_changed(): void
    {
        $anna = $this->givenUser();

        $response = $this->call('PATCH', '/me', [
            'displayName' => 'Änna B. 🚭', 'avatar' => 'avatar-12', 'locale' => 'en', 'timeZone' => 'America/New_York',
        ], $this->authorization($anna));

        self::assertSame(200, $response->status());
        $expected = [
            'id' => $anna->id->value,
            'email' => 'anna@example.org',
            'displayName' => 'Änna B. 🚭',
            'avatar' => 'avatar-12',
            'locale' => 'en',
            'timeZone' => 'America/New_York',
            'onboardingComplete' => false,
        ];
        self::assertSame($expected, $response->json());
        self::assertSame($expected, $this->call('GET', '/me', headers: $this->authorization($anna))->json());
        self::assertSame(
            [['display_name' => 'Änna B. 🚭', 'avatar_key' => 'avatar-12', 'locale' => 'en', 'timezone' => 'America/New_York']],
            $this->sql()->rows('SELECT display_name, avatar_key, locale, timezone FROM users'),
        );
    }

    public function test_FR_ACC_09_only_the_members_that_are_sent_are_changed(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $bob = $this->givenUser('bob@example.org');
        $before = $this->sql()->rows('SELECT * FROM users ORDER BY email');

        $response = $this->call('PATCH', '/me', ['locale' => 'en'], $this->authorization($anna));

        self::assertSame(200, $response->status());
        self::assertSame(['Test User', 'avatar-01', 'en', 'Europe/Berlin'], [
            $response->json()['displayName'] ?? null, $response->json()['avatar'] ?? null,
            $response->json()['locale'] ?? null, $response->json()['timeZone'] ?? null,
        ]);
        // Everything else of the row, the password hash included, and all of Bob's row are as before.
        $after = $this->sql()->rows('SELECT * FROM users ORDER BY email');
        $before[0]['locale'] = 'en';
        self::assertSame($before, $after);
        self::assertSame('de', $this->call('GET', '/me', headers: $this->authorization($bob))->json()['locale'] ?? null);
    }

    public function test_FR_ACC_09_the_display_name_is_stored_without_the_space_around_it(): void
    {
        $anna = $this->givenUser();

        $response = $this->call('PATCH', '/me', ['displayName' => '  Anna B.  '], $this->authorization($anna));

        self::assertSame('Anna B.', $response->json()['displayName'] ?? null);
        self::assertSame('Anna B.', $this->sql()->value('SELECT display_name FROM users'));
    }

    public function test_FR_ACC_09_every_invalid_member_is_reported_and_nothing_is_changed(): void
    {
        $anna = $this->givenUser();

        $response = $this->callInvalid('PATCH', '/me', [
            'displayName' => 'A', 'avatar' => 'avatar-99', 'locale' => 'fr', 'timeZone' => '+01:00',
        ], $this->authorization($anna));

        $problem = self::assertProblem(422, 'validation_failed', $response);
        self::assertSame(
            [
                ['field' => 'displayName', 'code' => 'too_short'],
                ['field' => 'avatar', 'code' => 'invalid_value'],
                ['field' => 'locale', 'code' => 'invalid_value'],
                ['field' => 'timeZone', 'code' => 'invalid_value'],
            ],
            self::fieldCodes($problem),
        );
        self::assertSame(
            [['display_name' => 'Test User', 'avatar_key' => 'avatar-01', 'locale' => 'de', 'timezone' => 'Europe/Berlin']],
            $this->sql()->rows('SELECT display_name, avatar_key, locale, timezone FROM users'),
        );
    }

    public function test_FR_ACC_09_one_invalid_member_stops_the_valid_ones_too(): void
    {
        $anna = $this->givenUser();

        $response = $this->callInvalid('PATCH', '/me', ['displayName' => 'Anna B.', 'locale' => 'fr'], $this->authorization($anna));

        self::assertProblem(422, 'validation_failed', $response);
        self::assertSame('Test User', $this->sql()->value('SELECT display_name FROM users'));
    }

    public function test_FR_ACC_09_a_display_name_of_two_characters_after_trimming_is_too_short_with_one(): void
    {
        $anna = $this->givenUser();

        // Six characters by the contract, one name character for the server.
        $response = $this->call('PATCH', '/me', ['displayName' => '  A   '], $this->authorization($anna));

        $problem = self::assertProblem(422, 'validation_failed', $response);
        self::assertSame([['field' => 'displayName', 'code' => 'too_short']], self::fieldCodes($problem));
    }

    public function test_FR_ACC_09_the_email_address_cannot_be_changed(): void
    {
        $anna = $this->givenUser();

        $response = $this->callInvalid('PATCH', '/me', ['email' => 'other@example.org', 'locale' => 'en'], $this->authorization($anna));

        $problem = self::assertProblem(422, 'validation_failed', $response);
        self::assertSame([['field' => 'email', 'code' => 'unknown_field']], self::fieldCodes($problem));
        self::assertSame([['email' => 'anna@example.org', 'locale' => 'de']], $this->sql()->rows('SELECT email, locale FROM users'));
    }

    public function test_FR_ACC_09_neither_the_id_nor_the_password_can_be_set_through_the_profile(): void
    {
        $anna = $this->givenUser();
        $before = $this->sql()->rows('SELECT * FROM users');

        foreach (['id' => '00000000-0000-7000-8000-000000000001', 'password' => 'another password', 'emailVerifiedAt' => null] as $member => $value) {
            $response = $this->callInvalid('PATCH', '/me', [$member => $value, 'locale' => 'en'], $this->authorization($anna));
            self::assertProblem(422, 'validation_failed', $response);
        }

        self::assertSame($before, $this->sql()->rows('SELECT * FROM users'));
    }

    public function test_FR_ACC_09_a_change_of_nothing_is_rejected(): void
    {
        $anna = $this->givenUser();

        foreach ([[], null] as $body) {
            $response = $this->callInvalid('PATCH', '/me', $body, $this->authorization($anna));

            $problem = self::assertProblem(422, 'validation_failed', $response);
            self::assertSame(
                [['field' => '', 'code' => 'required']],
                self::fieldCodes($problem),
            );
        }
    }

    public function test_FR_ACC_09_members_of_the_wrong_type_are_rejected(): void
    {
        $anna = $this->givenUser();

        $response = $this->callInvalid('PATCH', '/me', ['displayName' => 42, 'locale' => null, 'avatar' => ['avatar-01']], $this->authorization($anna));

        $problem = self::assertProblem(422, 'validation_failed', $response);
        self::assertSame(
            [
                ['field' => 'displayName', 'code' => 'wrong_type'],
                ['field' => 'avatar', 'code' => 'wrong_type'],
                ['field' => 'locale', 'code' => 'wrong_type'],
            ],
            self::fieldCodes($problem),
        );
    }

    public function test_NFR_05_the_profile_needs_a_token_and_a_body_cannot_name_another_user(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $this->givenUser('bob@example.org');

        self::assertProblem(401, 'unauthorized', $this->callInvalid('GET', '/me'));
        self::assertProblem(401, 'unauthorized', $this->callInvalid('PATCH', '/me', ['locale' => 'en']));
        // There is no way to address somebody else: a user id in the body is an unknown member.
        self::assertProblem(422, 'validation_failed', $this->callInvalid('PATCH', '/me', ['userId' => $anna->id->value, 'locale' => 'en'], $this->authorization($anna)));
        self::assertSame(['de', 'de'], $this->sql()->column('SELECT locale FROM users'));
    }
}
