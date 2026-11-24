<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Input;

use InvalidArgumentException;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;

/**
 * A local date as a member of a request body: an existing day as YYYY-MM-DD, as DateParameter reads
 * one in the path or the query.
 */
final readonly class DateField
{
    /**
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(string $input): LocalDate
    {
        try {
            return LocalDate::fromString($input);
        } catch (InvalidArgumentException) {
            throw new InvalidValue(ErrorCode::InvalidFormat, 'Must be an existing day as YYYY-MM-DD.');
        }
    }
}
