<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use RuntimeException;
use Slim\App;
use SmokeDetector\Config\Environment;
use SmokeDetector\Config\Settings;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\SettingsFixture;

/**
 * In production an unexpected error tells the client nothing about the inside of the server.
 */
final class ProductionErrorResponsesTest extends HttpTestCase
{
    protected function settings(): Settings
    {
        return SettingsFixture::modified(parent::settings(), environment: Environment::Production);
    }

    protected function routes(App $app): void
    {
        $app->get('/_test/fail', function (): never {
            throw new RuntimeException('LEAK: table smoke_detector.users, /home/www/secret/path');
        });
        $app->get('/_test/type-error', function (): never {
            // A PHP error, not an exception.
            throw new \TypeError('LEAK: argument #1 must be of type string');
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failingPaths(): iterable
    {
        yield 'exception' => ['/_test/fail'];
        yield 'php error' => ['/_test/type-error'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failingPaths')]
    public function test_an_unexpected_error_is_500_without_any_internal_detail(string $path): void
    {
        $response = $this->callUndocumented('GET', $path);

        self::assertSame(
            ['type' => 'internal_error', 'title' => 'Internal server error', 'status' => 500],
            self::assertProblem(500, 'internal_error', $response),
        );
        self::assertStringNotContainsString('LEAK', $response->body());
        foreach ($response->response->getHeaders() as $values) {
            self::assertStringNotContainsString('LEAK', implode(',', $values));
        }
    }

    public function test_the_detail_goes_to_the_log_instead(): void
    {
        $this->callUndocumented('GET', '/_test/fail');

        self::assertCount(1, $this->logs()->records);
        self::assertStringContainsString('LEAK: table smoke_detector.users', $this->logs()->records[0]['message']);
    }

    public function test_health_still_works_in_production_mode(): void
    {
        self::assertSame(200, $this->call('GET', '/health')->status());
    }
}
