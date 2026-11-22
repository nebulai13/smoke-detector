<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use SmokeDetector\Tests\Support\HttpTestCase;

/**
 * GET /health with the database up (HLD §7, §8 "smoke-test /api/v1/health").
 */
final class HealthTest extends HttpTestCase
{
    public function test_health_is_ok_with_the_release_and_no_cron_heartbeat_where_none_is_kept(): void
    {
        // No Authorization header: the deploy smoke test and monitoring have no account.
        // (The tests log to php://memory, so there is no place for a heartbeat. With one:
        // HealthWithCronHeartbeatTest.)
        $response = $this->call('GET', '/health');

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame(
            ['status' => 'ok', 'version' => $this->settings()->release, 'cronLastRunAt' => null],
            $response->json(),
        );
        self::assertSame('test-release', $this->settings()->release);
        self::assertSame([], $this->logs()->records);
    }

    public function test_NFR_12_the_api_is_only_served_under_its_versioned_base_path(): void
    {
        self::assertSame(200, $this->call('GET', '/health')->status());

        self::assertProblem(404, 'not_found', $this->callUndocumented('GET', '/health', basePath: ''));
        self::assertProblem(404, 'not_found', $this->callUndocumented('GET', '/health', basePath: '/api'));
        self::assertProblem(404, 'not_found', $this->callUndocumented('GET', '/health', basePath: '/api/v2'));
    }

    public function test_health_cannot_be_called_with_another_method(): void
    {
        $response = $this->callUndocumented('POST', '/health');

        self::assertProblem(405, 'method_not_allowed', $response);
        self::assertSame('GET', $response->header('Allow'));
    }
}
