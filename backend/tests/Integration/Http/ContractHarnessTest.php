<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use PHPUnit\Framework\AssertionFailedError;
use Slim\App;
use SmokeDetector\Tests\Support\Fake\CannedEndpoint;
use SmokeDetector\Tests\Support\HttpTestCase;

/**
 * The HTTP test harness itself: does it notice requests the contract forbids, and does it insist on
 * them where a test claims to send one?
 *
 * Uses a small contract of its own (tests/Support/Http/harness-contract.yaml) with a request body and
 * a protected operation, which the real contract does not have yet. The routes are test-only.
 */
final class ContractHarnessTest extends HttpTestCase
{
    private CannedEndpoint $things;

    private CannedEndpoint $secrets;

    protected function setUp(): void
    {
        $this->things = new CannedEndpoint();
        $this->secrets = new CannedEndpoint();
    }

    protected function contractFile(): string
    {
        return __DIR__ . '/../../Support/Http/harness-contract.yaml';
    }

    protected function routes(App $app): void
    {
        $app->post('/things', $this->things);
        $app->get('/secrets', $this->secrets);
        $app->post('/notes', $this->things);
        $app->get('/unlisted', $this->things);
    }

    public function test_a_valid_request_with_a_documented_response_passes(): void
    {
        $this->things->answer(201, 'application/json', '{"id":1,"name":"lighter"}');

        $response = $this->call('POST', '/things', ['name' => 'lighter']);

        self::assertSame(201, $response->status());
        self::assertSame(['id' => 1, 'name' => 'lighter'], $response->json());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null, string}>
     */
    public static function requestsTheContractForbids(): iterable
    {
        yield 'required field missing' => [[], 'name'];
        yield 'wrong type' => [['name' => 5], 'name'];
        yield 'undocumented field' => [['name' => 'lighter', 'colour' => 'red'], 'colour'];
        yield 'no body' => [null, 'Content-Type'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('requestsTheContractForbids')]
    public function test_call_refuses_to_send_a_request_the_contract_forbids(?array $body, string $reason): void
    {
        $this->things->answer(201, 'application/json', '{"id":1,"name":"lighter"}');

        $failure = $this->failureOf(fn () => $this->call('POST', '/things', $body));

        self::assertStringContainsString('The request POST /api/v1/things violates the contract', $failure);
        self::assertStringContainsStringIgnoringCase($reason, $failure);
        self::assertSame(0, $this->things->calls, 'The request was sent although the contract forbids it.');
    }

    public function test_a_request_without_a_body_is_valid_where_the_contract_makes_the_body_optional(): void
    {
        $this->things->answer(201, 'application/json', '{"id":1,"name":"note"}');

        // No body and no Content-Type, as a browser sends a POST that only carries a cookie.
        self::assertSame(201, $this->call('POST', '/notes')->status());
        self::assertSame(201, $this->call('POST', '/notes', ['text' => 'hello'])->status());
    }

    public function test_an_optional_body_that_is_sent_must_still_fit_the_contract(): void
    {
        $this->things->answer(201, 'application/json', '{"id":1,"name":"note"}');

        try {
            $this->call('POST', '/notes', ['text' => '']);
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('violates the contract', $failure->getMessage());

            return;
        }
        self::fail('The harness accepted a body that the contract forbids.');
    }

    public function test_a_request_without_a_body_is_invalid_where_the_contract_requires_the_body(): void
    {
        $this->things->answer(201, 'application/json', '{"id":1,"name":"lighter"}');

        try {
            $this->call('POST', '/things');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('violates the contract', $failure->getMessage());

            return;
        }
        self::fail('The harness accepted a request without the required body.');
    }

    public function test_call_invalid_sends_a_forbidden_request_and_checks_the_response(): void
    {
        $this->things->answer(
            422,
            'application/problem+json',
            '{"type":"validation_failed","title":"Validation failed","status":422,"errors":[{"field":"name","message":"Is required."}]}',
        );

        $response = $this->callInvalid('POST', '/things', []);

        self::assertProblem(422, 'validation_failed', $response);
        self::assertSame(1, $this->things->calls);
    }

    public function test_call_invalid_insists_that_the_request_breaks_the_contract(): void
    {
        // Otherwise a "rejects bad input" test could quietly send good input, or the contract could
        // allow what the server rejects.
        $failure = $this->failureOf(fn () => $this->callInvalid('POST', '/things', ['name' => 'lighter']));

        self::assertStringContainsString('The request POST /api/v1/things is valid by the contract.', $failure);
        self::assertSame(0, $this->things->calls);
    }

    public function test_call_invalid_still_checks_the_response(): void
    {
        $this->things->answer(422, 'application/problem+json', '{"type":"validation_failed","title":"Validation failed","status":422}');

        $failure = $this->failureOf(fn () => $this->callInvalid('POST', '/things', []));

        self::assertStringContainsString('The response to POST /api/v1/things (422 application/problem+json) violates the contract', $failure);
        self::assertStringContainsString('errors', $failure);
    }

    public function test_a_protected_operation_cannot_be_called_without_a_token_by_accident(): void
    {
        $this->secrets->answer(200, 'application/json', '{"id":1,"name":"secret"}');

        $failure = $this->failureOf(fn () => $this->call('GET', '/secrets'));

        self::assertStringContainsString('The request GET /api/v1/secrets violates the contract', $failure);
        self::assertSame(0, $this->secrets->calls);
    }

    public function test_a_protected_operation_is_called_with_a_bearer_token(): void
    {
        $this->secrets->answer(200, 'application/json', '{"id":1,"name":"secret"}');

        $response = $this->call('GET', '/secrets', headers: ['Authorization' => 'Bearer some.access.token']);

        self::assertSame(200, $response->status());
    }

    public function test_a_request_without_a_token_is_sent_as_an_invalid_one(): void
    {
        $this->secrets->answer(401, 'application/problem+json', '{"type":"unauthorized","title":"Unauthorized","status":401}');

        self::assertProblem(401, 'unauthorized', $this->callInvalid('GET', '/secrets'));
    }

    public function test_an_endpoint_that_is_not_in_the_contract_is_detected(): void
    {
        $this->things->answer(200, 'application/json', '{"id":1,"name":"lighter"}');

        $failure = $this->failureOf(fn () => $this->call('GET', '/unlisted'));

        self::assertStringContainsString('GET /api/v1/unlisted is not in the contract.', $failure);
        self::assertSame($failure, $this->failureOf(fn () => $this->callInvalid('GET', '/unlisted')));
        self::assertSame(0, $this->things->calls);
    }

    public function test_a_method_that_is_not_in_the_contract_is_detected(): void
    {
        $failure = $this->failureOf(fn () => $this->call('POST', '/secrets', ['name' => 'x']));

        self::assertStringContainsString('POST /api/v1/secrets is not in the contract.', $failure);
    }

    public function test_call_undocumented_is_only_for_what_is_not_in_the_contract(): void
    {
        // Otherwise it would be a way around the contract for a documented operation.
        $failure = $this->failureOf(fn () => $this->callUndocumented('POST', '/things', ['name' => 'lighter']));

        self::assertStringContainsString('POST /api/v1/things is in the contract.', $failure);
        self::assertSame(0, $this->things->calls);
    }

    public function test_call_undocumented_passes_a_test_only_route_through(): void
    {
        $this->things->answer(200, 'application/json', '{"anything":"goes"}');

        self::assertSame(['anything' => 'goes'], $this->callUndocumented('GET', '/unlisted')->json());
    }

    /**
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function errorsThatAreNotProblemDetails(): iterable
    {
        yield 'html' => [500, 'text/html', '<h1>Fatal error</h1>', 'is an error that is not sent as problem details'];
        yield 'plain json' => [404, 'application/json', '{"error":"not found"}', 'is an error that is not sent as problem details'];
        yield 'required member missing' => [404, 'application/problem+json', '{"type":"not_found","title":"Not found"}', 'not a valid Problem'];
        yield 'extra member' => [
            500,
            'application/problem+json',
            '{"type":"internal_error","title":"Internal server error","status":500,"trace":["a","b"]}',
            'not a valid Problem',
        ];
        yield 'validation problem without errors' => [
            422,
            'application/problem+json',
            '{"type":"validation_failed","title":"Validation failed","status":422}',
            'not a valid ValidationProblem',
        ];
        // Valid by the schema, which only asks for an integer, but a client would read another status.
        yield 'status member differs from the status' => [
            404,
            'application/problem+json',
            '{"type":"not_found","title":"Not found","status":500}',
            'carries another status in its problem body',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errorsThatAreNotProblemDetails')]
    public function test_an_error_must_be_problem_details_even_on_an_undocumented_route(int $status, string $contentType, string $body, string $reason): void
    {
        $this->things->answer($status, $contentType, $body);

        $failure = $this->failureOf(fn () => $this->callUndocumented('GET', '/unlisted'));

        self::assertStringContainsString($reason, $failure);
    }

    public function test_a_204_must_have_an_empty_body(): void
    {
        $this->things->answer(204, 'application/json', '{"done":true}');

        $failure = $this->failureOf(fn () => $this->callUndocumented('GET', '/unlisted'));

        self::assertStringContainsString('(204) is a 204 with a body.', $failure);
    }

    /**
     * The message of the test failure the harness raises.
     *
     * @param callable(): mixed $send
     */
    private function failureOf(callable $send): string
    {
        try {
            $send();
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        }

        self::fail('The harness accepted it.');
    }
}
