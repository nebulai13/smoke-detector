<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Legal;

use SmokeDetector\Config\ConsentVersions;
use SmokeDetector\Config\Settings;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\SettingsFixture;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * GET /legal/consents, and what a new version of a consent text does to registration (HLD §4.5
 * "Registration", FR-ACC-02, LEG-03). The privacy policy of this application is one version ahead
 * of the one the test accounts were made for: "test-privacy-v2" is in force.
 */
final class ConsentVersionsTest extends HttpTestCase
{
    private const array IN_FORCE = ['age18' => 'test-age18-v1', 'art9' => 'test-art9-v1', 'privacy' => 'test-privacy-v2'];

    protected function settings(): Settings
    {
        return SettingsFixture::modified(parent::settings(), consents: new ConsentVersions(...self::IN_FORCE));
    }

    public function test_FR_ACC_02_the_versions_of_the_consent_texts_in_force_can_be_read_by_anybody(): void
    {
        // No access token: the app asks before anybody has an account.
        $response = $this->call('GET', '/legal/consents');

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame(self::IN_FORCE, $response->json());
        // Never from a cache: after a text changed, the app must see the new version at once.
        self::assertSame('no-store', $response->header('Cache-Control'));
    }

    public function test_the_versions_answered_are_the_ones_of_the_settings(): void
    {
        $consents = $this->settings()->consents;

        self::assertSame(
            ['age18' => $consents->age18, 'art9' => $consents->art9, 'privacy' => $consents->privacy],
            $this->call('GET', '/legal/consents')->json(),
        );
    }

    public function test_the_versions_cannot_be_asked_for_with_another_method(): void
    {
        $response = $this->callUndocumented('POST', '/legal/consents');

        self::assertProblem(405, 'method_not_allowed', $response);
        self::assertSame('GET', $response->header('Allow'));
    }

    public function test_LEG_03_once_a_text_has_a_new_version_an_app_that_still_shows_the_old_one_cannot_register_anybody(): void
    {
        // The app was built when "test-privacy-v1" was in force, and sends what it showed.
        $outdated = $this->call('POST', '/auth/register', TestAccounts::registration());

        self::assertSame(
            [['field' => 'consents.privacy.version', 'code' => 'outdated_version']],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $outdated)),
        );
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM users'));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM consents'));
        self::assertSame([], $this->mails()->sent());
    }

    public function test_FR_ACC_02_an_app_that_shows_the_texts_in_force_registers_and_the_record_names_their_versions(): void
    {
        // What a client does: ask which versions are in force, show those texts, send their versions.
        $inForce = $this->call('GET', '/legal/consents')->json();
        $consents = [];
        foreach ($inForce as $type => $version) {
            $consents[$type] = ['accepted' => true, 'version' => $version];
        }

        $registered = $this->call('POST', '/auth/register', TestAccounts::registration(['consents' => $consents]));

        self::assertSame(202, $registered->status());
        self::assertSame(
            [['age18', 'test-age18-v1'], ['art9', 'test-art9-v1'], ['privacy', 'test-privacy-v2']],
            array_map(array_values(...), $this->sql()->rows('SELECT type, version FROM consents ORDER BY type')),
        );
        // LEG-03: the user reads the same record back in the export, with type, version and time.
        $session = $this->confirmEmail(self::tokenIn($this->mailTo('anna@example.org')))->json();
        self::assertIsString($session['accessToken'] ?? null);
        $export = $this->call('GET', '/me/export', headers: ['Authorization' => 'Bearer ' . $session['accessToken']])->json();
        self::assertSame(
            [
                ['type' => 'age18', 'version' => 'test-age18-v1', 'grantedAt' => '2026-11-17T12:00:00Z'],
                ['type' => 'art9', 'version' => 'test-art9-v1', 'grantedAt' => '2026-11-17T12:00:00Z'],
                ['type' => 'privacy', 'version' => 'test-privacy-v2', 'grantedAt' => '2026-11-17T12:00:00Z'],
            ],
            $export['consents'] ?? null,
        );
    }
}
