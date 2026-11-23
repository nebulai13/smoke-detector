<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\VisibleText;

/**
 * What a client calls the device it logs in from ("Annas iPhone"), to tell sessions apart later.
 */
final readonly class DeviceLabel
{
    public const int MAX_LENGTH = 100;

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(string $input): self
    {
        // The same rule as for a display name: the label is shown back in the list of sessions.
        $value = VisibleText::trimmed($input);
        if (VisibleText::hasForbiddenCharacters($value)) {
            throw new InvalidValue(
                ErrorCode::InvalidFormat,
                'Must not contain control characters, invisible characters or line breaks.',
            );
        }

        $length = mb_strlen($value, 'UTF-8');
        if ($length < 1) {
            throw new InvalidValue(ErrorCode::TooShort, 'Must be 1 to ' . self::MAX_LENGTH . ' characters long.');
        }
        if ($length > self::MAX_LENGTH) {
            throw new InvalidValue(ErrorCode::TooLong, 'Must be 1 to ' . self::MAX_LENGTH . ' characters long.');
        }
        if (VisibleText::visibleCharacters($value) < 1) {
            throw new InvalidValue(ErrorCode::TooShort, 'Must have at least 1 visible character.');
        }

        return new self($value);
    }
}
