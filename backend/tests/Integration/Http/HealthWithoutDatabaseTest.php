<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use SmokeDetector\Config\Settings;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\SettingsFixture;

/**
 * GET /health while the database server cannot be reached. A real failed connection, not a fake.
 */
final class HealthWithoutDatabaseTest extends HttpTestCase
{
    protected function settings(): Settings
    {
        $settings = parent::settings();

        return SettingsFixture::modified($settings, database: SettingsFixture::unreachableDatabase($settings));
    }

    public function test_health_is_503_problem_details_when_the_database_is_down(): void
    {
        $response = $this->call('GET', '/health');

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

    public function test_the_response_says_nothing_about_the_database_server(): void
    {
        $database = $this->settings()->database;

        $body = $this->call('GET', '/health')->body();

        // Development and tests show the details of unexpected errors. This is not one of them.
        self::assertStringNotContainsString($database->host, $body);
        self::assertStringNotContainsString($database->user, $body);
        self::assertStringNotContainsString($database->name, $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_the_outage_is_logged_with_its_cause_but_without_the_password(): void
    {
        $this->call('GET', '/health');

        $text = $this->logs()->text();
        self::assertStringContainsString('The database cannot be reached', $text);
        self::assertStringContainsString('SQLSTATE', $text);
        self::assertStringNotContainsString($this->settings()->database->password->reveal(), $text);
    }
}
