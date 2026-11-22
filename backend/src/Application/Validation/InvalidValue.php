<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Validation;

use InvalidArgumentException;

/**
 * A value from the input is not acceptable. Thrown by the value objects (EmailAddress::fromInput()
 * and the like), with the code clients translate by and an English message that does not repeat
 * the input.
 */
final class InvalidValue extends InvalidArgumentException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The same as the error of a request field.
     */
    public function forField(string $field): FieldError
    {
        return new FieldError($field, $this->errorCode, $this->getMessage());
    }
}
