<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Input;

use SensitiveParameter;
use SmokeDetector\Application\Auth\PasswordPolicy;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;

/**
 * A password that is presented to be checked (login, account deletion), as a request member.
 *
 * Not the password policy: that is for choosing a password. Here only what no password can be is
 * turned away, so that nobody can make the server hash megabytes.
 */
final readonly class PresentedPassword
{
    /**
     * @return string The password, exactly as it was sent.
     *
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(#[SensitiveParameter] string $input): string
    {
        if ($input === '' || mb_strlen($input, 'UTF-8') > PasswordPolicy::MAX_LENGTH) {
            throw new InvalidValue(
                $input === '' ? ErrorCode::TooShort : ErrorCode::TooLong,
                'Must be 1 to ' . PasswordPolicy::MAX_LENGTH . ' characters long.',
            );
        }

        return $input;
    }
}
