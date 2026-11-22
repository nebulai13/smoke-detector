<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Auth\PasswordPolicy;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\ValidationFailed;

/**
 * Which passwords can be chosen (FR-ACC-01: at least 10 characters. HLD §4.5: at most 200).
 */
final class PasswordPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ErrorCode|null, string|null}>
     */
    public static function passwords(): iterable
    {
        $length = 'Must be 10 to 200 characters long.';

        yield 'empty' => ['', ErrorCode::TooShort, $length];
        yield '9 characters' => [str_repeat('a', 9), ErrorCode::TooShort, $length];
        yield '9 characters that are 18 bytes' => [str_repeat('ä', 9), ErrorCode::TooShort, $length];
        yield '10 characters' => [str_repeat('a', 10), null, null];
        yield '10 spaces: nothing is trimmed' => [str_repeat(' ', 10), null, null];
        yield '200 characters of four bytes each' => [str_repeat('🔥', 200), null, null];
        yield '201 characters' => [str_repeat('a', 201), ErrorCode::TooLong, $length];
        yield 'not valid UTF-8, of a fine length' => [str_repeat("\xFF", 12), ErrorCode::InvalidFormat, 'Must be valid UTF-8 text.'];
        yield 'not valid UTF-8 and short' => ["\xFF", ErrorCode::InvalidFormat, 'Must be valid UTF-8 text.'];
    }

    #[DataProvider('passwords')]
    public function test_FR_ACC_01_a_password_has_10_to_200_characters_and_each_way_to_miss_that_has_its_code(string $password, ?ErrorCode $code, ?string $message): void
    {
        $violation = (new PasswordPolicy())->violation($password);

        self::assertSame([$code, $message], [$violation?->errorCode, $violation?->getMessage()]);
    }

    public function test_the_limits_are_those_of_the_contract(): void
    {
        self::assertSame([10, 200], [PasswordPolicy::MIN_LENGTH, PasswordPolicy::MAX_LENGTH]);
    }

    public function test_an_unacceptable_password_is_reported_for_the_field_it_came_in(): void
    {
        $policy = new PasswordPolicy();
        $policy->assertAcceptable('a perfectly fine password');

        foreach ([['password', []], ['newPassword', ['newPassword']]] as [$field, $arguments]) {
            try {
                $policy->assertAcceptable('short', ...$arguments);
                self::fail('Expected the password to be rejected.');
            } catch (ValidationFailed $failure) {
                self::assertEquals([new FieldError($field, ErrorCode::TooShort, 'Must be 10 to 200 characters long.')], $failure->errors);
            }
        }
    }

    public function test_no_message_repeats_the_password(): void
    {
        self::assertStringNotContainsString('hunter2', (string) (new PasswordPolicy())->violation('hunter2')?->getMessage());
    }
}
