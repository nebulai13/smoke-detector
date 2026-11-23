<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use SmokeDetector\Config\Settings;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\SettingsFixture;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * Any operation while the database server cannot be reached. A real failed connection, not a fake.
 *
 * The contract promises 503 "service_unavailable" for an outage on every operation, so that a client
 * repeats the request later instead of reporting a bug (500).
 */
final class WithoutDatabaseTest extends HttpTestCase
{
    protected function settings(): Settings
    {
        $settings = parent::settings();

        return SettingsFixture::modified($settings, database: SettingsFixture::unreachableDatabase($settings));
    }

    public function test_a_public_operation_is_503_problem_details_when_the_database_is_down(): void
    {
        $response = $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body']);

        self::assertSame(
            [
                'type' => 'service_unavailable',
                'title' => 'Service unavailable',
                'status' => 503,
                'detail' => 'The database cannot be reached.',
            ],
            self::assertProblem(503, 'service_unavailable', $response),
        );
    }

    public function test_registration_is_503_when_the_database_is_down(): void
    {
        $response = $this->call('POST', '/auth/register', TestAccounts::registration());

        self::assertProblem(503, 'service_unavailable', $response);
    }

    public function test_the_versions_of_the_consent_texts_are_answered_while_the_database_is_down(): void
    {
        // They are settings. An app can tell its user to update whatever state the server is in.
        $response = $this->call('GET', '/legal/consents');

        self::assertSame(200, $response->status());
        self::assertSame(TestAccounts::CONSENT_VERSIONS, $response->json());
        self::assertSame([], $this->logs()->records);
    }

    public function test_the_response_says_nothing_about_the_database_server(): void
    {
        $database = $this->settings()->database;

        $body = $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body'])->body();

        // Development and tests show the details of unexpected errors. This is not one of them.
        self::assertStringNotContainsString($database->host, $body);
        self::assertStringNotContainsString($database->user, $body);
        self::assertStringNotContainsString($database->name, $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_the_outage_is_logged_with_its_cause_but_without_the_password(): void
    {
        $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body']);

        $text = $this->logs()->text();
        self::assertStringContainsString('Cannot connect to the database', $text);
        self::assertStringContainsString('SQLSTATE', $text);
        self::assertStringNotContainsString($this->settings()->database->password->reveal(), $text);
        self::assertStringNotContainsString(TestAccounts::PASSWORD, $text);
    }
}
