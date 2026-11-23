<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\ValidationFailed;

/**
 * How a use case or an action says "this input is not acceptable": one entry per invalid field,
 * each with a stable code that clients translate, and an English message for developers.
 */
final class ValidationFailedTest extends TestCase
{
    public function test_it_carries_the_field_errors_in_the_given_order(): void
    {
        $errors = [
            new FieldError('email', ErrorCode::InvalidFormat, 'Must be an email address.'),
            new FieldError('consents.age18', ErrorCode::NotAccepted, 'Must be accepted.'),
        ];

        $failure = new ValidationFailed($errors);

        self::assertSame($errors, $failure->errors);
        self::assertSame('The request is invalid: email, consents.age18.', $failure->getMessage());
    }

    public function test_a_single_field_error_has_a_short_form(): void
    {
        $failure = ValidationFailed::forField('password', ErrorCode::TooShort, 'Must be at least 10 characters long.');

        self::assertEquals([new FieldError('password', ErrorCode::TooShort, 'Must be at least 10 characters long.')], $failure->errors);
    }

    public function test_the_exception_message_never_contains_the_messages_or_values(): void
    {
        // The exception message ends up in logs. Field names are fine there, what the user typed is not.
        $failure = ValidationFailed::forField('email', ErrorCode::InvalidFormat, 'LEAK is not an email address.');

        self::assertStringNotContainsString('LEAK', $failure->getMessage());
    }

    public function test_a_failure_without_any_field_error_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A validation failure needs at least one field error.');

        new ValidationFailed([]);
    }

    public function test_a_field_error_for_the_whole_body_has_an_empty_field_name(): void
    {
        $error = new FieldError('', ErrorCode::Required, 'A request body is required.');

        self::assertSame('', $error->field);
        self::assertSame(ErrorCode::Required, $error->code);
        self::assertSame('A request body is required.', $error->message);
    }

    public function test_a_field_error_needs_a_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A field error needs a message.');

        new FieldError('email', ErrorCode::InvalidFormat, '');
    }

    public function test_the_codes_are_a_small_closed_set_of_slugs(): void
    {
        // Part of the contract (api/openapi.yaml, schema FieldError): a code is never renamed or
        // removed, because clients translate by it. A new one is added here and there.
        self::assertSame(
            [
                'required', 'unknown_field', 'wrong_type', 'too_short', 'too_long', 'out_of_range',
                'invalid_format', 'invalid_value', 'not_accepted', 'must_be_below_baseline',
                'baseline_already_set', 'goal_target_mismatch', 'ambiguous', 'outdated_version',
            ],
            array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases()),
        );
    }

    public function test_a_rejected_value_carries_its_code_and_becomes_a_field_error(): void
    {
        $rejected = new InvalidValue(ErrorCode::TooLong, 'Must be at most 30 characters long.');

        // Still an InvalidArgumentException: code that builds a value object from stored data and
        // does not care about the reason keeps working.
        self::assertInstanceOf(InvalidArgumentException::class, $rejected);
        self::assertSame(ErrorCode::TooLong, $rejected->errorCode);
        self::assertSame('Must be at most 30 characters long.', $rejected->getMessage());
        self::assertEquals(
            new FieldError('displayName', ErrorCode::TooLong, 'Must be at most 30 characters long.'),
            $rejected->forField('displayName'),
        );
    }
}
