<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;

/**
 * The personal invite code of a user (FR-FRND-01, HLD §4.7): ten characters out of an alphabet
 * without the ones that are easily mixed up (0, 1, I, O). 32 characters to the power of 10 are 50
 * bits: a code cannot be guessed. Kept in capitals.
 */
final readonly class InviteCode
{
    public const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const int LENGTH = 10;

    private function __construct(public string $value)
    {
    }

    /**
     * Small letters are the same code. Nothing else is changed: no space is trimmed and no hyphen
     * dropped.
     *
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(string $input): self
    {
        // strtoupper() changes the letters a to z only, whatever the locale.
        $value = strtoupper($input);
        if (preg_match('/\A[' . self::ALPHABET . ']{' . self::LENGTH . '}\z/', $value) !== 1) {
            throw new InvalidValue(
                ErrorCode::InvalidFormat,
                'An invite code has ' . self::LENGTH . ' characters out of ' . self::ALPHABET . '.',
            );
        }

        return new self($value);
    }
}
