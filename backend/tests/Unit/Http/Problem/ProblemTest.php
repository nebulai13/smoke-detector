<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http\Problem;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;

/**
 * RFC 9457 problem details: the one shape of every error response (HLD §4.5 "Errors").
 */
final class ProblemTest extends TestCase
{
    public function test_a_problem_has_a_type_slug_a_title_and_a_status(): void
    {
        $problem = new Problem('invalid_credentials', 'Invalid credentials', 401);

        self::assertSame(
            ['type' => 'invalid_credentials', 'title' => 'Invalid credentials', 'status' => 401],
            $problem->toArray(),
        );
        self::assertSame([], $problem->headers);
    }

    public function test_detail_and_extension_members_are_added_after_the_standard_members(): void
    {
        $problem = new Problem('rate_limited', 'Too many requests', 429, 'Try again later.', ['retryAfter' => 60], ['Retry-After' => '60']);

        self::assertSame(
            ['type' => 'rate_limited', 'title' => 'Too many requests', 'status' => 429, 'detail' => 'Try again later.', 'retryAfter' => 60],
            $problem->toArray(),
        );
        self::assertSame(['Retry-After' => '60'], $problem->headers);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['NotFound'];
        yield 'dash' => ['not-found'];
        yield 'space' => ['not found'];
        yield 'url' => ['https://example.org/problems/not-found'];
        yield 'starts with a digit' => ['404_not_found'];
        yield 'trailing line break' => ["not_found\n"];
    }

    #[DataProvider('invalidTypes')]
    public function test_the_type_is_a_lower_case_slug(string $type): void
    {
        // Clients switch on the type, so it has one spelling: lower case words joined by "_".
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A problem type must be a slug of a-z, 0-9 and "_" that starts with a letter.');

        new Problem($type, 'Title', 400);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statusesThatAreNotErrors(): iterable
    {
        yield '200' => [200];
        yield '302' => [302];
        yield '399' => [399];
        yield '600' => [600];
        yield '0' => [0];
    }

    #[DataProvider('statusesThatAreNotErrors')]
    public function test_the_status_is_an_error_status(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A problem status must be from 400 to 599.');

        new Problem('some_problem', 'Title', $status);
    }

    public function test_the_edges_of_the_error_statuses_are_accepted(): void
    {
        self::assertSame(400, (new Problem('a', 'A', 400))->status);
        self::assertSame(599, (new Problem('a', 'A', 599))->status);
    }

    public function test_a_title_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A problem needs a title.');

        new Problem('some_problem', '', 400);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function standardMembers(): iterable
    {
        yield 'type' => ['type'];
        yield 'title' => ['title'];
        yield 'status' => ['status'];
        yield 'detail' => ['detail'];
        yield 'instance' => ['instance'];
    }

    #[DataProvider('standardMembers')]
    public function test_an_extension_member_cannot_replace_a_standard_member(string $member): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A problem extension cannot be named \"{$member}\".");

        new Problem('some_problem', 'Title', 400, null, [$member => 'x']);
    }

    public function test_the_general_problems_have_stable_types_and_statuses(): void
    {
        self::assertSame(
            ['type' => 'bad_request', 'title' => 'Bad request', 'status' => 400],
            Problem::badRequest()->toArray(),
        );
        self::assertSame(
            ['type' => 'malformed_json', 'title' => 'Malformed JSON', 'status' => 400, 'detail' => 'Why.'],
            Problem::malformedJson('Why.')->toArray(),
        );
        self::assertSame(
            ['type' => 'unauthorized', 'title' => 'Unauthorized', 'status' => 401],
            Problem::unauthorized()->toArray(),
        );
        self::assertSame(
            ['type' => 'forbidden', 'title' => 'Forbidden', 'status' => 403],
            Problem::forbidden()->toArray(),
        );
        self::assertSame(
            ['type' => 'not_found', 'title' => 'Not found', 'status' => 404],
            Problem::notFound()->toArray(),
        );
        self::assertSame(
            [
                'type' => 'payload_too_large',
                'title' => 'Payload too large',
                'status' => 413,
                'detail' => 'The request body may be at most 65536 bytes long.',
            ],
            Problem::payloadTooLarge(65536)->toArray(),
        );
        self::assertSame(
            [
                'type' => 'unsupported_media_type',
                'title' => 'Unsupported media type',
                'status' => 415,
                'detail' => 'The request body must be sent as application/json.',
            ],
            Problem::unsupportedMediaType()->toArray(),
        );
        self::assertSame(
            ['type' => 'internal_error', 'title' => 'Internal server error', 'status' => 500],
            Problem::internalError()->toArray(),
        );
        self::assertSame(
            ['type' => 'internal_error', 'title' => 'Internal server error', 'status' => 500, 'detail' => 'For developers.'],
            Problem::internalError('For developers.')->toArray(),
        );
        self::assertSame(
            ['type' => 'service_unavailable', 'title' => 'Service unavailable', 'status' => 503, 'detail' => 'The database cannot be reached.'],
            Problem::serviceUnavailable('The database cannot be reached.')->toArray(),
        );
        self::assertSame([], Problem::serviceUnavailable('The database cannot be reached.')->headers);
        // With a time to try again when there is one. Reported as at least one second.
        self::assertSame(['Retry-After' => '5'], Problem::serviceUnavailable('Busy.', 5)->headers);
        self::assertSame(['Retry-After' => '1'], Problem::serviceUnavailable('Busy.', 0)->headers);
    }

    public function test_NFR_05_a_missing_or_bad_access_token_is_answered_with_the_bearer_challenge(): void
    {
        // RFC 9110: a 401 says how to authenticate.
        self::assertSame(['WWW-Authenticate' => 'Bearer'], Problem::unauthorized()->headers);
    }

    public function test_FR_ACC_01_there_is_no_problem_of_its_own_for_an_unconfirmed_registration(): void
    {
        // A login answer that only an unconfirmed registration gets would tell who is registered.
        self::assertNotContains('emailNotVerified', get_class_methods(Problem::class));
    }

    public function test_the_auth_problems_have_stable_types_and_statuses_and_give_no_reason(): void
    {
        self::assertSame(
            ['type' => 'invalid_credentials', 'title' => 'Invalid credentials', 'status' => 401, 'detail' => 'The email address or the password is wrong.'],
            Problem::invalidCredentials()->toArray(),
        );
        self::assertSame(
            ['type' => 'invalid_token', 'title' => 'Invalid token', 'status' => 400, 'detail' => 'The token is unknown, expired or already used.'],
            Problem::invalidToken()->toArray(),
        );
        self::assertSame(
            ['type' => 'invalid_refresh_token', 'title' => 'Invalid refresh token', 'status' => 401, 'detail' => 'Log in again.'],
            Problem::invalidRefreshToken()->toArray(),
        );
        foreach ([Problem::invalidCredentials(), Problem::invalidToken(), Problem::invalidRefreshToken()] as $problem) {
            self::assertSame([], $problem->headers);
        }
    }

    public function test_FR_ACC_08_a_wrong_password_on_an_authenticated_request_is_not_a_token_problem(): void
    {
        $problem = Problem::wrongPassword();

        // 401 like a failed login, with the type of a failed login. But without the bearer challenge
        // of "unauthorized": a client must not answer it by refreshing its token.
        self::assertSame(
            ['type' => 'invalid_credentials', 'title' => 'Invalid credentials', 'status' => 401, 'detail' => 'The password is wrong.'],
            $problem->toArray(),
        );
        self::assertSame([], $problem->headers);
        self::assertSame(Problem::invalidCredentials()->title, $problem->title);
    }

    public function test_FR_ACC_03_a_wrong_password_on_the_confirmation_page_is_a_failed_login_that_names_nothing_of_the_registration(): void
    {
        $problem = Problem::wrongSignUpPassword();

        // The type of a failed login, so a client needs no new case. The detail tells developers
        // which of the meanings this is, and carries nothing of the registration.
        self::assertSame(
            ['type' => 'invalid_credentials', 'title' => 'Invalid credentials', 'status' => 401, 'detail' => 'The password is not the one of this sign-up.'],
            $problem->toArray(),
        );
        self::assertSame([], $problem->headers);
        self::assertSame(Problem::invalidCredentials()->title, $problem->title);
    }

    public function test_NFR_05_rate_limited_says_when_to_try_again(): void
    {
        $problem = Problem::rateLimited(873);

        self::assertSame(
            ['type' => 'rate_limited', 'title' => 'Too many requests', 'status' => 429, 'detail' => 'Try again in 873 seconds.'],
            $problem->toArray(),
        );
        self::assertSame(['Retry-After' => '873'], $problem->headers);
        // Never "retry now": a client that loops on it would hammer the server.
        self::assertSame(['Retry-After' => '1'], Problem::rateLimited(0)->headers);
        self::assertSame(['Retry-After' => '1'], Problem::rateLimited(-5)->headers);
        self::assertSame('Try again in 1 second.', Problem::rateLimited(1)->detail);
    }

    public function test_method_not_allowed_names_the_allowed_methods_in_the_allow_header(): void
    {
        $problem = Problem::methodNotAllowed(['GET', 'POST']);

        self::assertSame(
            ['type' => 'method_not_allowed', 'title' => 'Method not allowed', 'status' => 405],
            $problem->toArray(),
        );
        self::assertSame(['Allow' => 'GET, POST'], $problem->headers);
    }

    public function test_validation_failed_lists_field_code_and_message_of_every_error(): void
    {
        $problem = Problem::validationFailed([
            new FieldError('email', ErrorCode::InvalidFormat, 'Must be an email address.'),
            new FieldError('consents.age18', ErrorCode::NotAccepted, 'Must be accepted.'),
        ]);

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
            $problem->toArray(),
        );
    }

    public function test_a_problem_can_be_thrown(): void
    {
        $problem = new Problem('email_not_verified', 'Email not verified', 403);

        $exception = new ProblemException($problem);

        self::assertSame($problem, $exception->problem);
        self::assertSame('Email not verified', $exception->getMessage());
    }
}
