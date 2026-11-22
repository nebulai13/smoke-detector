<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http\Middleware;

use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Http\Middleware\JsonBodyMiddleware;
use SmokeDetector\Http\Problem\ProblemException;
use SmokeDetector\Tests\Support\Fake\CapturingRequestHandler;

/**
 * Request bodies are JSON objects of a limited size. Everything else is turned away before any
 * action sees it.
 */
final class JsonBodyMiddlewareTest extends TestCase
{
    public function test_a_json_object_becomes_the_parsed_body(): void
    {
        $parsed = $this->parsedBody(self::post('{"email":"a@example.test","consents":{"age18":true},"n":[1,2]}'));

        self::assertSame(['email' => 'a@example.test', 'consents' => ['age18' => true], 'n' => [1, 2]], $parsed);
    }

    public function test_an_empty_object_is_an_empty_parsed_body(): void
    {
        self::assertSame([], $this->parsedBody(self::post(' {} ')));
    }

    public function test_a_request_without_a_body_is_passed_on_untouched(): void
    {
        $request = new ServerRequest('GET', '/api/v1/health');
        $handler = new CapturingRequestHandler(new Response(204));

        $response = (new JsonBodyMiddleware(100))->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame($request, $handler->request);
        self::assertNull($request->getParsedBody());
    }

    public function test_an_empty_body_needs_no_content_type(): void
    {
        self::assertNull($this->parsedBody(new ServerRequest('POST', '/api/v1/x', [], '')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function jsonContentTypes(): iterable
    {
        yield 'plain' => ['application/json'];
        yield 'with charset' => ['application/json; charset=utf-8'];
        yield 'upper case' => ['Application/JSON'];
        yield 'structured suffix' => ['application/merge-patch+json'];
        yield 'suffix with parameter' => ['application/vnd.api+json;charset=UTF-8'];
    }

    #[DataProvider('jsonContentTypes')]
    public function test_json_media_types_are_accepted(string $contentType): void
    {
        self::assertSame(['a' => 1], $this->parsedBody(self::post('{"a":1}', $contentType)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherContentTypes(): iterable
    {
        yield 'none' => [''];
        yield 'form' => ['application/x-www-form-urlencoded'];
        yield 'multipart' => ['multipart/form-data; boundary=x'];
        yield 'text' => ['text/plain'];
        yield 'json as a parameter only' => ['text/plain; application/json'];
        yield 'almost json' => ['application/jsonp'];
        yield 'xml with json in the name' => ['application/json+xml'];
    }

    #[DataProvider('otherContentTypes')]
    public function test_a_body_that_is_not_declared_as_json_is_refused_with_415(string $contentType): void
    {
        $problem = $this->problem(self::post('{"a":1}', $contentType));

        self::assertSame('unsupported_media_type', $problem['type']);
        self::assertSame(415, $problem['status']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'cut off' => ['{"email":'];
        yield 'single quotes' => ["{'a':1}"];
        yield 'trailing comma' => ['{"a":1,}'];
        yield 'not json at all' => ['email=a@example.test'];
        yield 'two documents' => ['{"a":1}{"b":2}'];
        yield 'invalid utf-8' => ["{\"a\":\"\xC3\x28\"}"];
        yield 'only white space' => ["  \n"];
        // 41 levels in 87 bytes: within the size limit of these tests, beyond the depth limit of 32.
        yield 'nested too deep' => ['{"a":' . str_repeat('[', 40) . str_repeat(']', 40) . '}'];
    }

    #[DataProvider('malformedBodies')]
    public function test_malformed_json_is_refused_with_400(string $body): void
    {
        $problem = $this->problem(self::post($body));

        self::assertSame(
            ['type' => 'malformed_json', 'title' => 'Malformed JSON', 'status' => 400, 'detail' => 'The request body is not valid JSON.'],
            $problem,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsThatAreNotObjects(): iterable
    {
        yield 'list' => ['[{"a":1}]'];
        yield 'empty list' => ['[]'];
        yield 'text' => ['"hello"'];
        yield 'number' => ['42'];
        yield 'true' => ['true'];
        yield 'null' => ['null'];
    }

    #[DataProvider('documentsThatAreNotObjects')]
    public function test_valid_json_that_is_not_an_object_is_refused_with_400(string $body): void
    {
        $problem = $this->problem(self::post($body));

        self::assertSame(
            ['type' => 'malformed_json', 'title' => 'Malformed JSON', 'status' => 400, 'detail' => 'The request body must be a JSON object.'],
            $problem,
        );
    }

    public function test_a_body_of_exactly_the_limit_is_accepted(): void
    {
        $body = '{"a":"' . str_repeat('x', 92) . '"}';
        self::assertSame(100, strlen($body));

        self::assertSame(['a' => str_repeat('x', 92)], $this->parsedBody(self::post($body)));
    }

    public function test_a_body_one_byte_over_the_limit_is_refused_with_413(): void
    {
        $body = '{"a":"' . str_repeat('x', 93) . '"}';
        self::assertSame(101, strlen($body));

        $problem = $this->problem(self::post($body));

        self::assertSame(
            ['type' => 'payload_too_large', 'title' => 'Payload too large', 'status' => 413, 'detail' => 'The request body may be at most 100 bytes long.'],
            $problem,
        );
    }

    public function test_the_size_is_checked_before_the_content(): void
    {
        // Too large and not JSON and without a content type: the size is what is reported, and the
        // oversized body is never handed to the JSON parser.
        $problem = $this->problem(new ServerRequest('POST', '/api/v1/x', [], str_repeat('x', 5000)));

        self::assertSame('payload_too_large', $problem['type']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declaredLengths(): iterable
    {
        yield 'one over' => ['101'];
        yield 'huge' => ['99999999999999999999999999'];
    }

    #[DataProvider('declaredLengths')]
    public function test_a_declared_length_over_the_limit_is_refused_without_reading_the_body(string $length): void
    {
        $body = Stream::create('{}');
        $request = (new ServerRequest('POST', '/api/v1/x', ['Content-Type' => 'application/json', 'Content-Length' => $length]))
            ->withBody($body);

        $problem = $this->problem($request);

        self::assertSame('payload_too_large', $problem['type']);
        self::assertSame(0, $body->tell());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function honestLengths(): iterable
    {
        yield 'a small body' => ['{"a":1}', '7'];
        yield 'a body of exactly the limit' => ['{"a":"' . str_repeat('x', 92) . '"}', '100'];
        yield 'written with leading zeros, 18 digits' => ['{"a":1}', '000000000000000007'];
        yield 'not a number at all: the body itself is measured' => ['{"a":1}', 'seven'];
        yield 'a negative number: the body itself is measured' => ['{"a":1}', '-7'];
    }

    #[DataProvider('honestLengths')]
    public function test_a_declared_length_within_the_limit_lets_the_request_through(string $body, string $length): void
    {
        // Every real client sends Content-Length. The check before reading must not turn those away.
        $request = self::post($body)->withHeader('Content-Length', $length);

        self::assertIsArray($this->parsedBody($request));
    }

    public function test_a_declared_length_of_more_than_18_digits_is_refused_whatever_its_value(): void
    {
        // Longer than any length that fits an integer: not converted, refused.
        $request = self::post('{"a":1}')->withHeader('Content-Length', '0000000000000000007');

        self::assertSame('payload_too_large', $this->problem($request)['type']);
    }

    public function test_of_a_body_that_is_far_too_large_only_one_byte_more_than_the_limit_is_read(): void
    {
        // No Content-Length (or a lying one): the body is measured by reading it, and reading stops
        // as soon as it is known to be too large. Megabytes are never pulled into memory.
        $body = Stream::create(str_repeat('x', 500_000));
        $request = (new ServerRequest('POST', '/api/v1/x', ['Content-Type' => 'application/json']))->withBody($body);

        self::assertSame('payload_too_large', $this->problem($request)['type']);
        self::assertSame(101, $body->tell());
    }

    public function test_a_declared_length_cannot_talk_a_large_body_past_the_limit(): void
    {
        $request = self::post('{"a":"' . str_repeat('x', 500) . '"}')->withHeader('Content-Length', '10');

        self::assertSame('payload_too_large', $this->problem($request)['type']);
    }

    public function test_a_body_that_was_already_read_is_read_from_the_start(): void
    {
        $request = self::post('{"a":1}');
        $request->getBody()->getContents();

        self::assertSame(['a' => 1], $this->parsedBody($request));
    }

    public function test_numbers_beyond_the_integer_range_are_not_rounded(): void
    {
        // As a float 9223372036854775808 would silently become 9.2233720368547758E+18.
        self::assertSame(['n' => '9223372036854775808'], $this->parsedBody(self::post('{"n":9223372036854775808}')));
    }

    public function test_the_default_limit_is_64_kibibytes(): void
    {
        self::assertSame(65_536, JsonBodyMiddleware::DEFAULT_MAX_BYTES);

        $middleware = new JsonBodyMiddleware();
        $fits = '{"a":"' . str_repeat('x', 65_536 - 8) . '"}';
        $handler = new CapturingRequestHandler(new Response());
        $middleware->process(self::post($fits), $handler);
        self::assertNotNull($handler->request);

        $this->expectException(ProblemException::class);
        $middleware->process(self::post('{"a":"' . str_repeat('x', 65_536 - 7) . '"}'), new CapturingRequestHandler(new Response()));
    }

    public function test_the_limit_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The request body limit must be at least 1 byte.');

        new JsonBodyMiddleware(0);
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function requestsWhoseMethodHasNoBody(): iterable
    {
        foreach (['GET', 'HEAD'] as $method) {
            yield "{$method} with broken JSON" => [$method, '{bad', ['Content-Type' => 'application/json']];
            yield "{$method} with a body that is not declared as JSON" => [$method, 'hello', ['Content-Type' => 'text/plain']];
            yield "{$method} with a body over the limit" => [$method, str_repeat('x', 101), ['Content-Type' => 'application/json']];
            yield "{$method} with only a declared length over the limit" => [$method, '', ['Content-Length' => '70000']];
            yield "{$method} with a JSON object" => [$method, '{"a":1}', ['Content-Type' => 'application/json']];
        }
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('requestsWhoseMethodHasNoBody')]
    public function test_a_request_whose_method_has_no_body_is_passed_on_without_a_look_at_what_it_carries(string $method, string $body, array $headers): void
    {
        // No operation of the contract takes a body with GET. Refusing one here would answer
        // 400, 413 or 415 before the token is looked at, and the contract lists none of them there.
        $request = new ServerRequest($method, '/api/v1/me', $headers, $body);
        $handler = new CapturingRequestHandler(new Response());

        (new JsonBodyMiddleware(100))->process($request, $handler);

        self::assertSame($request, $handler->request);
        self::assertNull($request->getParsedBody());
    }

    private static function post(string $body, string $contentType = 'application/json'): ServerRequestInterface
    {
        $headers = $contentType === '' ? [] : ['Content-Type' => $contentType];

        return new ServerRequest('POST', '/api/v1/x', $headers, $body);
    }

    /**
     * The parsed body the next handler receives. The limit is 100 bytes.
     */
    private function parsedBody(ServerRequestInterface $request): mixed
    {
        $handler = new CapturingRequestHandler(new Response());

        (new JsonBodyMiddleware(100))->process($request, $handler);

        self::assertNotNull($handler->request, 'The request did not reach the application.');

        return $handler->request->getParsedBody();
    }

    /**
     * The problem the request is refused with. The limit is 100 bytes.
     *
     * @return array<string, mixed>
     */
    private function problem(ServerRequestInterface $request): array
    {
        $handler = new CapturingRequestHandler(new Response());

        try {
            (new JsonBodyMiddleware(100))->process($request, $handler);
        } catch (ProblemException $exception) {
            self::assertNull($handler->request, 'The request reached the application.');

            return $exception->problem->toArray();
        }

        self::fail('The request was accepted.');
    }
}
