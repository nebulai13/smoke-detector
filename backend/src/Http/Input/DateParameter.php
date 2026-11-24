<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Input;

use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\InvalidValue;

/**
 * A local date in the path or the query of a request: an existing day as YYYY-MM-DD.
 */
final readonly class DateParameter
{
    /**
     * @param mixed $value What the request has under that name: null when it is missing.
     *
     * @return LocalDate|FieldError The date, or what is wrong with it.
     */
    public static function read(string $field, mixed $value): LocalDate|FieldError
    {
        if ($value === null) {
            return new FieldError($field, ErrorCode::Required, 'Is required.');
        }
        if (!is_string($value)) {
            return new FieldError($field, ErrorCode::WrongType, 'Must be a single date.');
        }

        try {
            return DateField::fromInput($value);
        } catch (InvalidValue $rejected) {
            return $rejected->forField($field);
        }
    }
}
