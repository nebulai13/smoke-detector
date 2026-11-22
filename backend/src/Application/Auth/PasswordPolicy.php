<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SensitiveParameter;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\ValidationFailed;

/**
 * Which passwords are accepted when one is chosen (FR-ACC-01, HLD §4.5: 10 to 200 characters).
 *
 * Only the length is judged. Rules about character classes make passwords worse, not better, and
 * the upper bound only keeps the work of hashing bounded.
 */
final readonly class PasswordPolicy
{
    public const int MIN_LENGTH = 10;
    public const int MAX_LENGTH = 200;

    /**
     * @return InvalidValue|null What is wrong, for the client, or null when the password is acceptable.
     */
    public function violation(#[SensitiveParameter] string $password): ?InvalidValue
    {
        // A password that is not valid UTF-8 cannot arrive in JSON. Should one come another way, it
        // is not accepted: its length cannot be told.
        if (!mb_check_encoding($password, 'UTF-8')) {
            return new InvalidValue(ErrorCode::InvalidFormat, 'Must be valid UTF-8 text.');
        }

        // Characters (Unicode code points), not bytes: "ä" counts once.
        $length = mb_strlen($password, 'UTF-8');
        $message = 'Must be ' . self::MIN_LENGTH . ' to ' . self::MAX_LENGTH . ' characters long.';
        if ($length < self::MIN_LENGTH) {
            return new InvalidValue(ErrorCode::TooShort, $message);
        }
        if ($length > self::MAX_LENGTH) {
            return new InvalidValue(ErrorCode::TooLong, $message);
        }

        return null;
    }

    /**
     * @throws ValidationFailed
     */
    public function assertAcceptable(#[SensitiveParameter] string $password, string $field = 'password'): void
    {
        $violation = $this->violation($password);
        if ($violation !== null) {
            throw new ValidationFailed([$violation->forField($field)]);
        }
    }
}
