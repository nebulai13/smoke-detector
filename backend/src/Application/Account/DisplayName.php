<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\VisibleText;

/**
 * The name friends see (FR-ACC-01, HLD §4.5: 2 to 30 characters, at least 2 of them visible, none of
 * the characters VisibleText forbids).
 */
final readonly class DisplayName
{
    public const int MIN_LENGTH = 2;
    public const int MAX_LENGTH = 30;

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(string $input): self
    {
        $value = VisibleText::trimmed($input);
        if (VisibleText::hasForbiddenCharacters($value)) {
            throw new InvalidValue(
                ErrorCode::InvalidFormat,
                'Must not contain control characters, invisible characters or line breaks.',
            );
        }

        $length = mb_strlen($value, 'UTF-8');
        $message = 'Must be ' . self::MIN_LENGTH . ' to ' . self::MAX_LENGTH . ' characters long.';
        if ($length < self::MIN_LENGTH) {
            throw new InvalidValue(ErrorCode::TooShort, $message);
        }
        if ($length > self::MAX_LENGTH) {
            throw new InvalidValue(ErrorCode::TooLong, $message);
        }
        // Friends must have something to read: two characters that only count (an emoji and its
        // variation selector, a letter and an accent, fillers that are drawn as nothing) are no name.
        if (VisibleText::visibleCharacters($value) < self::MIN_LENGTH) {
            throw new InvalidValue(ErrorCode::TooShort, 'Must have at least ' . self::MIN_LENGTH . ' visible characters.');
        }

        return new self($value);
    }
}
