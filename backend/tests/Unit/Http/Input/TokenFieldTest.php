<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http\Input;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Http\Input\TokenField;
use Throwable;

/**
 * A token as a request member. The contract gives every token maxLength 200 and counts text in
 * Unicode code points, not in bytes (api/openapi.yaml, "Request bodies").
 */
final class TokenFieldTest extends TestCase
{
    public function test_a_token_is_returned_as_it_was_sent(): void
    {
        self::assertSame('abc.DEF-123_xyz', TokenField::fromInput('abc.DEF-123_xyz'));
        self::assertSame(' padded ', TokenField::fromInput(' padded '));
    }

    public function test_a_token_of_200_characters_is_accepted_and_one_of_201_is_not(): void
    {
        self::assertSame(str_repeat('a', 200), TokenField::fromInput(str_repeat('a', 200)));
        self::assertSame('Must be 1 to 200 characters long.', self::refusal(str_repeat('a', 201)));
    }

    public function test_the_length_is_counted_in_characters_not_in_bytes(): void
    {
        // 200 characters of two bytes each: within the contract, and 400 bytes long.
        self::assertSame(str_repeat('é', 200), TokenField::fromInput(str_repeat('é', 200)));
        self::assertSame('Must be 1 to 200 characters long.', self::refusal(str_repeat('é', 201)));
    }

    public function test_an_empty_token_is_refused(): void
    {
        self::assertSame('Must be 1 to 200 characters long.', self::refusal(''));
    }

    public function test_the_refusal_does_not_repeat_the_token(): void
    {
        self::assertStringNotContainsString('SECRET', (string) self::refusal(str_repeat('SECRET', 40)));
    }

    private static function refusal(string $input): ?string
    {
        try {
            TokenField::fromInput($input);
        } catch (Throwable $refused) {
            return $refused->getMessage();
        }

        return null;
    }
}
