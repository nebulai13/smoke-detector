<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use Nyholm\Psr7\ServerRequest;
use SmokeDetector\Http\FrontController;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\SettingsFixture;

/**
 * The one way into the application for a web request: settings, container, Slim app, response.
 * public/index.php only calls it.
 */
final class FrontControllerTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $reported = [];

    public function test_a_request_is_answered_by_the_application_built_from_the_environment(): void
    {
        $response = $this->frontController()->handle(new ServerRequest('GET', '/api/v1/health'), getenv());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '{"status":"ok","version":"test-release","cronLastRunAt":null}',
            (string) $response->getBody(),
        );
        self::assertSame([], $this->reported);
    }

    public function test_an_application_that_cannot_start_answers_500_problem_details_without_saying_why(): void
    {
        $environment = SettingsFixture::environment();
        $environment['JWT_SECRET'] = 'LEAK-short';

        $response = $this->frontController()->handle(new ServerRequest('GET', '/api/v1/health'), $environment);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            '{"type":"internal_error","title":"Internal server error","status":500}',
            (string) $response->getBody(),
        );
        // Even this response carries the security headers.
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('max-age=31536000; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_the_reason_goes_to_the_server_log_with_the_name_of_the_setting_and_not_its_value(): void
    {
        $environment = SettingsFixture::environment();
        $environment['JWT_SECRET'] = 'LEAK-short';

        $this->frontController()->handle(new ServerRequest('GET', '/api/v1/health'), $environment);

        self::assertCount(1, $this->reported);
        self::assertStringContainsString('The application could not start', $this->reported[0]);
        self::assertStringContainsString('JWT_SECRET', $this->reported[0]);
        self::assertStringNotContainsString('LEAK', $this->reported[0]);
    }

    public function test_a_missing_settings_file_stops_the_application(): void
    {
        $response = $this->frontController()->handle(
            new ServerRequest('GET', '/api/v1/health'),
            ['SETTINGS_FILE' => '/no/such/file.php'] + SettingsFixture::environment(),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('SETTINGS_FILE', $this->reported[0] ?? '');
    }

    private function frontController(): FrontController
    {
        return new FrontController(function (string $message): void {
            $this->reported[] = $message;
        });
    }
}
