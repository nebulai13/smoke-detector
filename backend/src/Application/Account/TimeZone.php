<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use DateTimeZone;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;

/**
 * The IANA time zone all day logic of a user runs in (FR-ACC-01, FR-I18N-02).
 */
final readonly class TimeZone
{
    private function __construct(public string $name)
    {
    }

    /**
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(string $input): self
    {
        // The list, not "new DateTimeZone($input)": PHP would also take offsets and abbreviations
        // ("+01:00", "cest"), which have no daylight saving rules. The older names are included
        // because browsers still report some of them ("Asia/Calcutta").
        if (!in_array($input, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidValue(ErrorCode::InvalidValue, 'Must be an IANA time zone name such as "Europe/Berlin".');
        }

        return new self($input);
    }
}
