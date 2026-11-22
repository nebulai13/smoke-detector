<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http\Error;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpNotImplementedException;
use Slim\Exception\HttpUnauthorizedException;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\TemporarilyUnavailable;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Http\Error\ErrorHandler;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Infrastructure\Database\DatabaseConnectionFailed;
use SmokeDetector\Http\Problem\ProblemException;
use SmokeDetector\Tests\Support\Fake\RecordingLogger;
use Throwable;

/**
 * Whatever goes wrong, the client gets RFC 9457 problem details with a stable type, and nothing
 * about the inside of the server unless the environment allows it.
 */
final class ErrorHandlerTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function test_a_validation_failure_is_422_with_the_list_of_field_errors(): void
    {
        $response = $this->handle(new ValidationFailed([
            new FieldError('email', ErrorCode::InvalidFormat, 'Must be an email address.'),
            new FieldError('password', ErrorCode::TooShort, 'Must be at least 10 characters long.'),
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            [
                'type' => 'validation_failed',
                'title' => 'Validation failed',
                'status' => 422,
                'errors' => [
                    ['field' => 'email', 'code' => 'invalid_format', 'message' => 'Must be an email address.'],
                    ['field' => 'password', 'code' => 'too_short', 'message' => 'Must be at least 10 characters long.'],
                ],
            ],
            self::body($response),
        );
        self::assertSame([], $this->logger->records);
    }

    public function test_NFR_05_an_exceeded_rate_limit_is_429_with_retry_after(): void
    {
        $response = $this->handle(new RateLimitExceeded(120));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('120', $response->getHeaderLine('Retry-After'));
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['type' => 'rate_limited', 'title' => 'Too many requests', 'status' => 429, 'detail' => 'Try again in 120 seconds.'],
            self::body($response),
        );
        self::assertSame([], $this->logger->records);
    }

    public function test_FR_ACC_08_a_request_for_an_account_that_was_deleted_meanwhile_is_answered_like_a_bad_token(): void
    {
        $response = $this->handle(new AccountNotFound());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['type' => 'unauthorized', 'title' => 'Unauthorized', 'status' => 401], self::body($response));
        self::assertSame([], $this->logger->records);
    }

    public function test_a_thrown_problem_is_answered_as_it_is_with_its_headers(): void
    {
        $problem = new Problem('rate_limited', 'Too many requests', 429, 'Try again in a minute.', [], ['Retry-After' => '60']);

        $response = $this->handle(new ProblemException($problem));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['type' => 'rate_limited', 'title' => 'Too many requests', 'status' => 429, 'detail' => 'Try again in a minute.'],
            self::body($response),
        );
        self::assertSame([], $this->logger->records);
    }

    public function test_an_unknown_route_is_404(): void
    {
        $response = $this->handle(new HttpNotFoundException(self::request()));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['type' => 'not_found', 'title' => 'Not found', 'status' => 404], self::body($response));
        self::assertSame([], $this->logger->records);
    }

    public function test_a_known_route_with_another_method_is_405_and_names_the_allowed_methods(): void
    {
        $exception = (new HttpMethodNotAllowedException(self::request()))->setAllowedMethods(['GET', 'PUT']);

        $response = $this->handle($exception);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, PUT', $response->getHeaderLine('Allow'));
        self::assertSame(
            ['type' => 'method_not_allowed', 'title' => 'Method not allowed', 'status' => 405],
            self::body($response),
        );
    }

    /**
     * @return iterable<string, array{Throwable, int, string}>
     */
    public static function frameworkExceptions(): iterable
    {
        yield '400' => [new HttpBadRequestException(self::request(), 'LEAK framework message'), 400, 'bad_request'];
        yield '401' => [new HttpUnauthorizedException(self::request(), 'LEAK framework message'), 401, 'unauthorized'];
        yield '403' => [new HttpForbiddenException(self::request(), 'LEAK framework message'), 403, 'forbidden'];
    }

    #[DataProvider('frameworkExceptions')]
    public function test_http_exceptions_of_the_framework_get_our_types_and_none_of_their_text(Throwable $exception, int $status, string $type): void
    {
        $response = $this->handle($exception, displayErrorDetails: true);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($type, self::body($response)['type']);
        self::assertStringNotContainsString('LEAK', (string) $response->getBody());
    }

    public function test_an_unexpected_exception_is_500_without_any_internal_detail(): void
    {
        $exception = new RuntimeException('LEAK: SQLSTATE[42S02] table smoke_detector.users not found at /home/www/app');

        $response = $this->handle($exception, displayErrorDetails: false);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['type' => 'internal_error', 'title' => 'Internal server error', 'status' => 500],
            self::body($response),
        );
        self::assertStringNotContainsString('LEAK', (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function unexpectedErrors(): iterable
    {
        yield 'exception' => [new RuntimeException('boom')];
        yield 'programming error' => [new LogicException('boom')];
        yield 'php error' => [new \TypeError('boom')];
        yield 'framework exception without a mapping' => [new HttpNotImplementedException(self::request())];
        yield 'http exception with an unknown code' => [new \Slim\Exception\HttpException(self::request(), 'boom', 418)];
    }

    #[DataProvider('unexpectedErrors')]
    public function test_everything_unexpected_is_a_500(Throwable $error): void
    {
        $response = $this->handle($error, displayErrorDetails: false);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            ['type' => 'internal_error', 'title' => 'Internal server error', 'status' => 500],
            self::body($response),
        );
        self::assertCount(1, $this->logger->records);
    }

    public function test_outside_production_a_500_describes_the_exception(): void
    {
        $exception = new RuntimeException('The mail server refused the connection.');

        $response = $this->handle($exception, displayErrorDetails: true);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            [
                'type' => 'internal_error',
                'title' => 'Internal server error',
                'status' => 500,
                'detail' => 'RuntimeException: The mail server refused the connection. (ErrorHandlerTest.php:' . $exception->getLine() . ')',
            ],
            self::body($response),
        );
    }

    public function test_an_unexpected_exception_is_logged_with_where_it_came_from(): void
    {
        $cause = new LogicException('The cause.');
        $exception = new RuntimeException('The failure.', 0, $cause);
        $request = new ServerRequest('POST', 'https://example.test/api/v1/auth/login?token=LEAK-in-query');

        $this->handle($exception, displayErrorDetails: false, request: $request);

        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame('error', $record['level']);
        self::assertSame('Unhandled exception: RuntimeException: The failure.', $record['message']);
        self::assertSame('POST', $record['context']['method'] ?? null);
        // The path only. A query string can carry tokens, which never belong in a log.
        self::assertSame('/api/v1/auth/login', $record['context']['path'] ?? null);
        self::assertSame(RuntimeException::class, $record['context']['exception'] ?? null);
        self::assertSame(__FILE__, $record['context']['file'] ?? null);
        self::assertSame($exception->getLine(), $record['context']['line'] ?? null);
        self::assertSame(['LogicException: The cause.'], $record['context']['previous'] ?? null);
        self::assertIsArray($record['context']['trace'] ?? null);
        self::assertStringNotContainsString('LEAK', $this->logger->text());
    }

    public function test_the_logged_stack_trace_has_no_argument_values(): void
    {
        // PHP's own trace text includes the arguments of every call: passwords and tokens among them.
        try {
            self::failWith('LEAK-password-argument');
        } catch (RuntimeException $exception) {
            $this->handle($exception, displayErrorDetails: false);
        }

        // The trace as PHP keeps it, not as text: how much of a text argument the text form shows
        // depends on a php.ini setting (zend.exception_string_param_max_length).
        self::assertSame(['LEAK-password-argument'], $exception->getTrace()[0]['args'] ?? null, 'The test needs an argument in the trace.');
        self::assertCount(1, $this->logger->records);
        $trace = $this->logger->records[0]['context']['trace'] ?? null;
        self::assertIsArray($trace);
        self::assertIsString($trace[0] ?? null);
        self::assertStringContainsString('ErrorHandlerTest', $trace[0]);
        self::assertStringContainsString('failWith', $trace[0]);
        self::assertStringNotContainsString('LEAK', $this->logger->text());
    }

    public function test_a_logged_trace_line_is_class_call_file_and_line_and_there_are_at_most_30_of_them(): void
    {
        try {
            self::recurse(40);
        } catch (RuntimeException $exception) {
            $this->handle($exception);
        }

        $trace = $this->logger->records[0]['context']['trace'] ?? null;
        self::assertIsArray($trace);
        // Deep recursion is cut: a log line must stay a log line.
        self::assertCount(30, $trace);
        foreach ($trace as $line) {
            self::assertIsString($line);
            self::assertMatchesRegularExpression('~\A' . preg_quote(self::class, '~') . '::recurse \(' . preg_quote(__FILE__, '~') . ':\d+\)\z~', $line);
        }
        // A frame without class, file and line (a PHP function calling back) is still one readable line.
        try {
            array_map(static fn (int $depth): never => throw new RuntimeException('from a callback'), [1]);
        } catch (RuntimeException $fromCallback) {
            $this->handle($fromCallback);
        }
        $trace = $this->logger->records[1]['context']['trace'] ?? null;
        self::assertIsArray($trace);
        self::assertContains('array_map (' . __FILE__ . ':' . (__LINE__ - 6) . ')', $trace);
        self::assertIsString($trace[0] ?? null);
        self::assertSame(1, preg_match('~\A\S+\{closure[^ ]*\}? \(internal:0\)\z~', $trace[0]), $trace[0]);
    }

    private static function recurse(int $depth): never
    {
        if ($depth === 0) {
            throw new RuntimeException('deep');
        }
        self::recurse($depth - 1);
    }

    public function test_a_thrown_problem_with_a_server_error_status_is_logged_too(): void
    {
        $response = $this->handle(new ProblemException(Problem::serviceUnavailable('The database cannot be reached.')));

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $this->logger->records);
    }

    public function test_the_response_is_valid_json_whatever_the_exception_message_contains(): void
    {
        $response = $this->handle(new RuntimeException("Broken \xC3\x28 bytes and \"quotes\""), displayErrorDetails: true);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('internal_error', self::body($response)['type']);
    }

    public function test_work_the_database_gave_up_for_now_is_503_with_retry_after_and_is_logged(): void
    {
        // A deadlock or a lock wait that timed out is nobody's fault and gone a moment later. The
        // client is told so (503 and when to try again), not "500, something is broken".
        $response = $this->handle(new TemporarilyUnavailable(2, new RuntimeException('SQLSTATE[40001]: Deadlock found, token=LEAK')), true);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('2', $response->getHeaderLine('Retry-After'));
        self::assertSame(
            ['type' => 'service_unavailable', 'title' => 'Service unavailable', 'status' => 503, 'detail' => 'The service is busy. Try again in a moment.'],
            self::body($response),
        );
        // It is logged, with the cause: deadlocks that keep happening are a bug to find.
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString(TemporarilyUnavailable::class, $this->logger->records[0]['message']);
    }

    public function test_a_database_that_cannot_be_reached_is_503_without_details_and_is_logged(): void
    {
        // An outage is not a bug of ours: the client is told to come back, also where error details are shown.
        $failure = new DatabaseConnectionFailed(new PDOException('SQLSTATE[HY000] [2002] Connection refused to db.internal as app_user'));

        $response = $this->handle($failure, displayErrorDetails: true);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Retry-After'));
        self::assertSame(
            ['type' => 'service_unavailable', 'title' => 'Service unavailable', 'status' => 503, 'detail' => 'The database cannot be reached.'],
            self::body($response),
        );
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString(DatabaseConnectionFailed::class, $this->logger->records[0]['message']);
    }

    private function handle(Throwable $exception, bool $displayErrorDetails = false, ?ServerRequest $request = null): ResponseInterface
    {
        $factory = new Psr17Factory();
        $handler = new ErrorHandler(new JsonResponder($factory, $factory), $this->logger);

        return $handler($request ?? self::request(), $exception, $displayErrorDetails, false, false);
    }

    private static function request(): ServerRequest
    {
        return new ServerRequest('GET', '/api/v1/somewhere');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function body(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private static function failWith(string $argument): never
    {
        throw new RuntimeException('Failed with an argument of ' . strlen($argument) . ' bytes.');
    }
}
