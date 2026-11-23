<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Http\RequestBody;

/**
 * How an action gets at the JSON object of the request.
 */
final class RequestBodyTest extends TestCase
{
    public function test_the_members_of_the_json_object_are_returned_by_name(): void
    {
        $request = (new ServerRequest('POST', '/api/v1/x'))->withParsedBody(['email' => 'a@example.test', 'consents' => ['age18' => true]]);

        self::assertSame(['email' => 'a@example.test', 'consents' => ['age18' => true]], RequestBody::of($request));
    }

    public function test_a_request_without_a_body_has_no_members(): void
    {
        // The action then reports each required field as missing, instead of a special "no body" error.
        self::assertSame([], RequestBody::of(new ServerRequest('POST', '/api/v1/x')));
    }

    public function test_a_body_that_was_not_parsed_by_the_json_middleware_is_a_programming_error(): void
    {
        $request = (new ServerRequest('POST', '/api/v1/x'))->withParsedBody(new \stdClass());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The request body was not parsed by JsonBodyMiddleware.');

        RequestBody::of($request);
    }
}
