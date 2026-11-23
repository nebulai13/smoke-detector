<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Me;

use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Privacy\DatabaseContents;
use SmokeDetector\Tests\Support\Privacy\UserDataFixture;
use SmokeDetector\Tests\Support\RateLimitKey;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * GET /me/export through the real application and database (HLD §4.5 "Account", FR-ACC-07).
 * What each table contributes is tested in UserDataTablesTest.
 */
final class ExportTest extends HttpTestCase
{
    public function test_FR_ACC_07_the_export_is_one_document_with_everything_stored_about_the_user(): void
    {
        $anna = $this->givenUser();
        (new UserDataFixture($this->service(Sql::class)))->fill($anna->id, 1);

        $response = $this->call('GET', '/me/export', headers: $this->authorization($anna));

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        $export = $response->json();
        self::assertSame(
            [
                'formatVersion', 'exportedAt', 'profile', 'consents', 'plan', 'planHistory', 'baselineHistory', 'timeZoneHistory', 'smokeLogs', 'days',
                'progress', 'xpEvents', 'sessions', 'mailLinks', 'queuedMails',
            ],
            array_keys($export),
        );
        self::assertSame(1, $export['formatVersion']);
        self::assertSame('2026-11-17T12:00:00Z', $export['exportedAt']);
        self::assertSame(
            [
                'id' => $anna->id->value,
                'email' => 'anna@example.org',
                'displayName' => 'Test User',
                'avatar' => 'avatar-01',
                'locale' => 'de',
                'timeZone' => 'Europe/Berlin',
                'emailVerifiedAt' => '2026-11-17T12:00:00Z',
                'createdAt' => '2026-11-17T12:00:00Z',
            ],
            $export['profile'],
        );
        self::assertIsArray($export['consents']);
        self::assertSame(['type' => 'age18', 'version' => 'age-v1', 'grantedAt' => '2026-11-01T08:00:00Z'], $export['consents'][0] ?? null);
        self::assertSame(
            ['goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'gentle', 'defaultProduct' => 'rolled', 'startDate' => '2026-11-02', 'rulesVersion' => 'v1'],
            $export['plan'],
        );
        $lists = [
            'consents' => 3, 'planHistory' => 2, 'baselineHistory' => 3, 'timeZoneHistory' => 2, 'smokeLogs' => 2, 'days' => 2, 'xpEvents' => 2,
            'sessions' => 2, 'mailLinks' => 2, 'queuedMails' => 2,
        ];
        foreach ($lists as $member => $entries) {
            self::assertIsArray($export[$member]);
            self::assertCount($entries, $export[$member], $member);
        }
    }

    public function test_LEG_03_the_consent_records_can_be_audited_who_agreed_to_what_in_which_version_and_when(): void
    {
        // The record is what the registration stored, and the user can read it back at any time:
        // each of the three consents with the version of its text in force then, and the time.
        $this->clock()->travelTo(new \DateTimeImmutable('2026-11-17 09:30:15 UTC'));
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $this->clock()->advanceSeconds(3600);
        $session = $this->confirmEmail(self::tokenIn($this->mailTo('anna@example.org')))->json();
        self::assertIsString($session['accessToken'] ?? null);

        $export = $this->call('GET', '/me/export', headers: ['Authorization' => 'Bearer ' . $session['accessToken']])->json();

        $expected = [
            ['type' => 'age18', 'version' => 'test-age18-v1', 'grantedAt' => '2026-11-17T09:30:15Z'],
            ['type' => 'art9', 'version' => 'test-art9-v1', 'grantedAt' => '2026-11-17T09:30:15Z'],
            ['type' => 'privacy', 'version' => 'test-privacy-v1', 'grantedAt' => '2026-11-17T09:30:15Z'],
        ];
        self::assertSame($expected, $export['consents'] ?? null);
        // And it is exactly what is in the table: the export shows the record, not a summary of it.
        self::assertSame(
            [['age18', 'test-age18-v1', '2026-11-17 09:30:15'], ['art9', 'test-art9-v1', '2026-11-17 09:30:15'], ['privacy', 'test-privacy-v1', '2026-11-17 09:30:15']],
            array_map(array_values(...), $this->sql()->rows('SELECT type, version, granted_at FROM consents ORDER BY type')),
        );
    }

    public function test_FR_ACC_07_the_export_of_what_the_user_really_did_through_the_api(): void
    {
        // Registered, confirmed (which logs in) and onboarded over HTTP: no row was written by a fixture.
        $this->call('POST', '/auth/register', TestAccounts::registration());
        $session = $this->call('POST', '/auth/verify-email', self::confirmation(self::tokenIn($this->mailTo('anna@example.org'))) + ['deviceLabel' => 'Annas iPhone'])->json();
        self::assertIsString($session['accessToken'] ?? null);
        $headers = ['Authorization' => 'Bearer ' . $session['accessToken']];
        $this->call('PUT', '/me/plan', ['startingBaseline' => 12, 'goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'fast', 'defaultProduct' => 'vape'], $headers);

        $export = $this->call('GET', '/me/export', headers: $headers)->json();

        self::assertIsArray($export['profile']);
        self::assertSame(['anna@example.org', 'Anna', 'avatar-03'], [$export['profile']['email'], $export['profile']['displayName'], $export['profile']['avatar']]);
        self::assertSame(
            [
                ['type' => 'age18', 'version' => 'test-age18-v1', 'grantedAt' => '2026-11-17T12:00:00Z'],
                ['type' => 'art9', 'version' => 'test-art9-v1', 'grantedAt' => '2026-11-17T12:00:00Z'],
                ['type' => 'privacy', 'version' => 'test-privacy-v1', 'grantedAt' => '2026-11-17T12:00:00Z'],
            ],
            $export['consents'],
        );
        self::assertSame(
            ['goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'fast', 'defaultProduct' => 'vape', 'startDate' => '2026-11-17', 'rulesVersion' => 'v1'],
            $export['plan'],
        );
        self::assertSame(
            [['validFrom' => '2026-11-17', 'value' => 12.0, 'source' => 'onboarding', 'weekMean' => null, 'monthMean' => null, 'weekDays' => null, 'monthDays' => null, 'rulesVersion' => 'v1']],
            $export['baselineHistory'],
        );
        self::assertIsArray($export['sessions']);
        self::assertCount(1, $export['sessions']);
        self::assertIsArray($export['sessions'][0]);
        self::assertSame(
            ['Annas iPhone', '2026-11-17T12:00:00Z', '2027-01-16T12:00:00Z', null, null],
            [$export['sessions'][0]['deviceLabel'], $export['sessions'][0]['createdAt'], $export['sessions'][0]['expiresAt'], $export['sessions'][0]['lastRefreshedAt'], $export['sessions'][0]['revokedAt']],
        );
        self::assertSame(
            [['purpose' => 'verify_email', 'expiresAt' => '2026-11-18T12:00:00Z', 'usedAt' => '2026-11-17T12:00:00Z']],
            $export['mailLinks'],
        );
    }

    public function test_NFR_05_the_export_carries_no_password_hash_and_no_token_in_any_form(): void
    {
        $anna = $this->givenUser();
        (new UserDataFixture($this->service(Sql::class)))->fill($anna->id, 1);
        $session = $this->logIn()->json();
        self::assertIsString($session['accessToken'] ?? null);
        self::assertIsString($session['refreshToken'] ?? null);

        $body = $this->call('GET', '/me/export', headers: ['Authorization' => 'Bearer ' . $session['accessToken']])->body();

        $secrets = [
            TestAccounts::PASSWORD,
            TestAccounts::PASSWORD_HASH,
            'argon2',
            $session['accessToken'],
            $session['refreshToken'],
            hash('sha256', $session['refreshToken']),
            ...UserDataFixture::secrets(1),
        ];
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
        // Every hash and password hash that is stored anywhere, whatever table it is in.
        foreach (DatabaseContents::all($this->sql()) as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $column => $value) {
                    if (is_string($value) && str_contains($column, 'hash')) {
                        self::assertStringNotContainsString($value, $body, "A value of the column {$column} is in the export.");
                    }
                }
            }
        }
    }

    public function test_FR_ACC_07_a_user_who_has_only_registered_gets_the_profile_and_empty_lists(): void
    {
        $anna = $this->givenUser();

        $export = $this->call('GET', '/me/export', headers: $this->authorization($anna))->json();

        $lists = ['consents', 'planHistory', 'baselineHistory', 'timeZoneHistory', 'smokeLogs', 'days', 'xpEvents', 'sessions', 'mailLinks', 'queuedMails'];
        // No plan and no progress before the onboarding.
        self::assertSame(['formatVersion', 'exportedAt', 'profile', ...$lists], array_keys($export));
        foreach ($lists as $member) {
            self::assertSame([], $export[$member], $member);
        }
    }

    public function test_FR_ACC_07_the_export_holds_the_data_of_the_user_of_the_token_and_of_nobody_else(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $bob = $this->givenUser('bob@example.org');
        (new UserDataFixture($this->service(Sql::class)))->fill($anna->id, 1);
        (new UserDataFixture($this->service(Sql::class)))->fill($bob->id, 2);

        $annas = $this->call('GET', '/me/export', headers: $this->authorization($anna))->body();
        $bobs = $this->call('GET', '/me/export', headers: $this->authorization($bob))->body();

        self::assertStringContainsString($anna->id->value, $annas);
        self::assertStringContainsString('Phone 1', $annas);
        foreach ([$bob->id->value, 'bob@example.org', 'Phone 2'] as $ofBob) {
            self::assertStringNotContainsString($ofBob, $annas);
            self::assertStringContainsString($ofBob, $bobs);
        }
    }

    public function test_FR_ACC_07_an_export_changes_nothing_in_any_table(): void
    {
        $anna = $this->givenUser();
        (new UserDataFixture($this->service(Sql::class)))->fill($anna->id, 1);
        $headers = $this->authorization($anna);
        $before = DatabaseContents::all($this->sql(), ['rate_limits']);

        $this->call('GET', '/me/export', headers: $headers);

        self::assertSame($before, DatabaseContents::all($this->sql(), ['rate_limits']));
    }

    public function test_NFR_05_the_sixth_export_within_an_hour_is_refused_with_retry_after(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $bob = $this->givenUser('bob@example.org');
        $this->clock()->advanceSeconds(600);
        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(200, $this->call('GET', '/me/export', headers: $this->authorization($anna))->status());
        }

        $refused = $this->call('GET', '/me/export', headers: $this->authorization($anna));

        self::assertProblem(429, 'rate_limited', $refused);
        // It is 12:10, the hour ends at 13:00.
        self::assertSame('3000', $refused->header('Retry-After'));
        // The limit is the account's: another user, from the same client address, is not affected.
        self::assertSame(200, $this->call('GET', '/me/export', headers: $this->authorization($bob))->status());

        $this->clock()->advanceSeconds(3000);
        self::assertSame(200, $this->call('GET', '/me/export', headers: $this->authorization($anna))->status());
    }

    public function test_NFR_05_the_export_counter_holds_no_user_id(): void
    {
        $anna = $this->givenUser();

        $this->call('GET', '/me/export', headers: $this->authorization($anna));

        self::assertSame(
            [['key' => RateLimitKey::of('export.account', $anna->id->value), 'window_start' => '2026-11-17 12:00:00', 'hits' => 1]],
            $this->sql()->rows('SELECT * FROM rate_limits'),
        );
    }

    public function test_NFR_05_without_a_token_nothing_is_exported(): void
    {
        $this->givenUser();

        $response = $this->callInvalid('GET', '/me/export');

        self::assertProblem(401, 'unauthorized', $response);
        self::assertStringNotContainsString('anna@example.org', $response->body());
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM rate_limits'));
    }
}
