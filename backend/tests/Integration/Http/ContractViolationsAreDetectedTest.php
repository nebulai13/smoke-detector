<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use PHPUnit\Framework\AssertionFailedError;
use SmokeDetector\Http\Action\HealthAction;
use SmokeDetector\Tests\Support\Fake\CannedEndpoint;
use SmokeDetector\Tests\Support\HttpTestCase;

/**
 * Proof that the contract check bites, against the real contract api/openapi.yaml.
 *
 * The action behind GET /health is replaced by a fake that answers wrongly on purpose. Every one of
 * these responses would pass a test that only looks at the status code.
 */
final class ContractViolationsAreDetectedTest extends HttpTestCase
{
    private CannedEndpoint $health;

    protected function setUp(): void
    {
        $this->health = new CannedEndpoint();
    }

    protected function services(): array
    {
        return [HealthAction::class => $this->health];
    }

    public function test_control_the_documented_response_passes(): void
    {
        $this->health->answer(200, 'application/json', '{"status":"ok","version":"abc","cronLastRunAt":"2026-11-17T12:00:00Z"}');

        self::assertSame(200, $this->call('GET', '/health')->status());
        self::assertSame(1, $this->health->calls);
    }

    /**
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function wrongResponses(): iterable
    {
        yield 'required field missing' => [200, 'application/json', '{"status":"ok","cronLastRunAt":null}', 'version'];
        yield 'undocumented field' => [
            200,
            'application/json',
            '{"status":"ok","version":"abc","cronLastRunAt":null,"databaseHost":"db.internal"}',
            'databaseHost',
        ];
        yield 'wrong type' => [200, 'application/json', '{"status":"ok","version":7,"cronLastRunAt":null}', 'version'];
        yield 'value outside the enum' => [200, 'application/json', '{"status":"degraded","version":"abc","cronLastRunAt":null}', 'status'];
        yield 'null where it is not allowed' => [200, 'application/json', '{"status":"ok","version":null,"cronLastRunAt":null}', 'version'];
        yield 'not a date-time' => [200, 'application/json', '{"status":"ok","version":"abc","cronLastRunAt":"yesterday"}', 'cronLastRunAt'];
        yield 'undocumented status code' => [201, 'application/json', '{"status":"ok","version":"abc","cronLastRunAt":null}', '201'];
        yield 'undocumented content type' => [200, 'text/html', '<p>ok</p>', 'text/html'];
        yield 'body of another response' => [503, 'application/problem+json', '{"status":"ok","version":"abc","cronLastRunAt":null}', 'type'];
        yield 'problem with an extra member' => [
            503,
            'application/problem+json',
            '{"type":"service_unavailable","title":"Service unavailable","status":503,"exception":"PDOException"}',
            'exception',
        ];
        yield 'problem type that is not a slug' => [
            503,
            'application/problem+json',
            '{"type":"Service Unavailable","title":"Service unavailable","status":503}',
            'type',
        ];
        yield 'not json' => [200, 'application/json', 'ok', 'JSON'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongResponses')]
    public function test_a_response_that_differs_from_the_contract_fails_the_test(int $status, string $contentType, string $body, string $reason): void
    {
        $this->health->answer($status, $contentType, $body);

        try {
            $this->call('GET', '/health');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('violates the contract', $failure->getMessage());
            self::assertStringContainsStringIgnoringCase($reason, $failure->getMessage());
            self::assertSame(1, $this->health->calls);

            return;
        }

        self::fail('The harness accepted a response that the contract does not allow.');
    }
}
