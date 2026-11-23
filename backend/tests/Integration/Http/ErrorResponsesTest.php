<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Middleware\JsonBodyMiddleware;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;
use SmokeDetector\Tests\Support\HttpTestCase;

/**
 * Every error of the application is RFC 9457 problem details with a stable type (HLD §4.5 "Errors").
 *
 * The failing routes are test-only: the real API has no endpoint yet that takes a body or can fail
 * on purpose. They run through the real application, with its real middleware and error handler.
 */
final class ErrorResponsesTest extends HttpTestCase
{
    protected function routes(App $app): void
    {
        $json = $this->service(JsonResponder::class);

        $app->post('/_test/echo', function (ServerRequestInterface $request) use ($json): ResponseInterface {
            return $json->json(['received' => $request->getParsedBody()]);
        });
        $app->post('/_test/validate', function (): never {
            throw new ValidationFailed([
                new FieldError('email', ErrorCode::InvalidFormat, 'Must be an email address.'),
                new FieldError('consents.age18', ErrorCode::NotAccepted, 'Must be accepted.'),
            ]);
        });
        $app->get('/_test/problem', function (): never {
            throw new ProblemException(new Problem('rate_limited', 'Too many requests', 429, null, [], ['Retry-After' => '900']));
        });
        $app->get('/_test/fail', function (): never {
            throw new RuntimeException('LEAK: table smoke_detector.users, /home/www/secret/path');
        });
    }

    public function test_an_unknown_path_is_404(): void
    {
        $problem = self::assertProblem(404, 'not_found', $this->callUndocumented('GET', '/no/such/path'));

        self::assertSame(['type' => 'not_found', 'title' => 'Not found', 'status' => 404], $problem);
        self::assertSame([], $this->logs()->records);
    }

    public function test_a_known_path_with_another_method_is_405_with_the_allowed_methods(): void
    {
        $response = $this->callUndocumented('DELETE', '/_test/echo');

        self::assertProblem(405, 'method_not_allowed', $response);
        self::assertSame('POST', $response->header('Allow'));
    }

    public function test_a_json_object_reaches_the_action_as_it_was_sent(): void
    {
        $body = ['email' => 'zoë@example.test', 'consents' => ['age18' => true], 'note' => '🚭', 'list' => [1, 2.5, null]];

        $response = $this->callUndocumented('POST', '/_test/echo', $body);

        self::assertSame(200, $response->status());
        self::assertSame(['received' => $body], $response->json());
    }

    public function test_malformed_json_is_400(): void
    {
        $problem = self::assertProblem(400, 'malformed_json', $this->callUndocumented('POST', '/_test/echo', '{"email": '));

        self::assertSame('The request body is not valid JSON.', $problem['detail'] ?? null);
    }

    public function test_json_that_is_not_an_object_is_400(): void
    {
        $problem = self::assertProblem(400, 'malformed_json', $this->callUndocumented('POST', '/_test/echo', '["a"]'));

        self::assertSame('The request body must be a JSON object.', $problem['detail'] ?? null);
    }

    public function test_a_body_that_is_not_json_is_415(): void
    {
        $response = $this->callUndocumented('POST', '/_test/echo', 'email=a%40example.test', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        self::assertProblem(415, 'unsupported_media_type', $response);
    }

    public function test_a_body_over_the_size_limit_is_413(): void
    {
        $atTheLimit = '{"a":"' . str_repeat('x', JsonBodyMiddleware::DEFAULT_MAX_BYTES - 8) . '"}';
        self::assertSame(200, $this->callUndocumented('POST', '/_test/echo', $atTheLimit)->status());

        $overTheLimit = '{"a":"' . str_repeat('x', JsonBodyMiddleware::DEFAULT_MAX_BYTES - 7) . '"}';
        $problem = self::assertProblem(413, 'payload_too_large', $this->callUndocumented('POST', '/_test/echo', $overTheLimit));

        self::assertSame('The request body may be at most 65536 bytes long.', $problem['detail'] ?? null);
    }

    public function test_the_route_is_looked_up_before_the_body_is_read(): void
    {
        // An unknown path stays a 404, whatever was sent to it.
        self::assertProblem(404, 'not_found', $this->callUndocumented('POST', '/no/such/path', '{"broken'));
    }

    public function test_a_validation_failure_is_422_with_field_code_and_message_of_every_error(): void
    {
        $problem = self::assertProblem(422, 'validation_failed', $this->callUndocumented('POST', '/_test/validate', ['email' => 'x']));

        self::assertSame(
            [
                'type' => 'validation_failed',
                'title' => 'Validation failed',
                'status' => 422,
                'errors' => [
                    ['field' => 'email', 'code' => 'invalid_format', 'message' => 'Must be an email address.'],
                    ['field' => 'consents.age18', 'code' => 'not_accepted', 'message' => 'Must be accepted.'],
                ],
            ],
            $problem,
        );
        self::assertSame([], $this->logs()->records);
    }

    public function test_a_problem_thrown_by_an_action_is_sent_with_its_headers(): void
    {
        $response = $this->callUndocumented('GET', '/_test/problem');

        self::assertProblem(429, 'rate_limited', $response);
        self::assertSame('900', $response->header('Retry-After'));
    }

    public function test_an_unexpected_exception_is_500_and_logged(): void
    {
        $response = $this->callUndocumented('GET', '/_test/fail');

        $problem = self::assertProblem(500, 'internal_error', $response);
        // The test environment may describe the exception, to make a failing test readable.
        self::assertIsString($problem['detail'] ?? null);
        self::assertStringStartsWith('RuntimeException: LEAK', $problem['detail']);

        self::assertCount(1, $this->logs()->records);
        self::assertSame('error', $this->logs()->records[0]['level']);
        self::assertStringContainsString('RuntimeException', $this->logs()->records[0]['message']);
        self::assertSame('/api/v1/_test/fail', $this->logs()->records[0]['context']['path'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, string|null, int}>
     */
    public static function responses(): iterable
    {
        yield 'success' => ['POST', '/_test/echo', '{}', 200];
        yield 'not found' => ['GET', '/nothing', null, 404];
        yield 'method not allowed' => ['PUT', '/_test/echo', null, 405];
        yield 'malformed body' => ['POST', '/_test/echo', '{', 400];
        yield 'validation failure' => ['POST', '/_test/validate', '{}', 422];
        yield 'thrown problem' => ['GET', '/_test/problem', null, 429];
        yield 'unexpected exception' => ['GET', '/_test/fail', null, 500];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('responses')]
    public function test_NFR_05_every_response_carries_hsts_and_the_other_security_headers(string $method, string $path, ?string $body, int $status): void
    {
        $response = $this->callUndocumented($method, $path, $body);

        self::assertSame($status, $response->status());
        self::assertSame(
            [
                'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
                'Cross-Origin-Resource-Policy' => 'same-origin',
            ],
            [
                'Strict-Transport-Security' => $response->header('Strict-Transport-Security'),
                'Cache-Control' => $response->header('Cache-Control'),
                'X-Content-Type-Options' => $response->header('X-Content-Type-Options'),
                'Content-Security-Policy' => $response->header('Content-Security-Policy'),
                'X-Frame-Options' => $response->header('X-Frame-Options'),
                'Referrer-Policy' => $response->header('Referrer-Policy'),
                'Cross-Origin-Resource-Policy' => $response->header('Cross-Origin-Resource-Policy'),
            ],
        );
    }
}
