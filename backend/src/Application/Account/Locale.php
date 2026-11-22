<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;

/**
 * The languages the app speaks (FR-I18N-01). Mails are written in the user's one.
 */
enum Locale: string
{
    case De = 'de';
    case En = 'en';

    /**
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(string $input): self
    {
        return self::tryFrom($input) ?? throw new InvalidValue(ErrorCode::InvalidValue, 'Must be "de" or "en".');
    }
}
