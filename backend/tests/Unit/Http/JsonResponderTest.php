<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Problem\Problem;

/**
 * The two kinds of response body: JSON data and problem details.
 */
final class JsonResponderTest extends TestCase
{
    public function test_data_is_sent_as_application_json(): void
    {
        $response = $this->responder()->json(['status' => 'ok', 'cronLastRunAt' => null]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"status":"ok","cronLastRunAt":null}', (string) $response->getBody());
    }

    public function test_the_status_can_be_chosen(): void
    {
        self::assertSame(202, $this->responder()->json(['status' => 'verification_sent'], 202)->getStatusCode());
    }

    public function test_text_is_sent_as_utf8_without_escaping(): void
    {
        $response = $this->responder()->json(['name' => 'Zoë 🚭', 'url' => 'https://example.test/a', 'ratio' => 7.0]);

        self::assertSame('{"name":"Zoë 🚭","url":"https://example.test/a","ratio":7.0}', (string) $response->getBody());
    }

    public function test_an_object_without_members_stays_an_object(): void
    {
        self::assertSame('{}', (string) $this->responder()->json([])->getBody());
    }

    public function test_a_problem_is_sent_as_application_problem_json_with_its_status_and_headers(): void
    {
        $problem = new Problem('rate_limited', 'Too many requests', 429, null, [], ['Retry-After' => '30']);

        $response = $this->responder()->problem($problem);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('30', $response->getHeaderLine('Retry-After'));
        self::assertSame('{"type":"rate_limited","title":"Too many requests","status":429}', (string) $response->getBody());
    }

    private function responder(): JsonResponder
    {
        $factory = new Psr17Factory();

        return new JsonResponder($factory, $factory);
    }
}
