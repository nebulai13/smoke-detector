<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Input;

use SensitiveParameter;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;

/**
 * A secret token as a request member: the token of a mail link, a refresh token.
 *
 * Only its size is checked here. Whether it is a token we issued is decided by looking up its hash,
 * and the answer to that is the same for every token that is not: "invalid".
 */
final readonly class TokenField
{
    public const int MAX_LENGTH = 200;

    /**
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(#[SensitiveParameter] string $input): string
    {
        // In characters, as the contract counts every length (api/openapi.yaml, "Request bodies").
        if ($input === '' || mb_strlen($input, 'UTF-8') > self::MAX_LENGTH) {
            throw new InvalidValue(
                $input === '' ? ErrorCode::TooShort : ErrorCode::TooLong,
                'Must be 1 to ' . self::MAX_LENGTH . ' characters long.',
            );
        }

        return $input;
    }
}
