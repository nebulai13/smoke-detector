<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Validation;

use InvalidArgumentException;
use RuntimeException;

/**
 * The input is readable but not acceptable. Thrown by actions (shape of the request) and by use
 * cases (rules that need data), and answered as 422 "validation_failed" with the list of errors.
 */
final class ValidationFailed extends RuntimeException
{
    /** @var non-empty-list<FieldError> */
    public readonly array $errors;

    /**
     * @param list<FieldError> $errors
     */
    public function __construct(array $errors)
    {
        if ($errors === []) {
            throw new InvalidArgumentException('A validation failure needs at least one field error.');
        }
        $this->errors = $errors;

        // Only the field names: this message may be logged, and the error messages are for the client.
        $fields = array_map(static fn (FieldError $error): string => $error->field, $errors);
        parent::__construct('The request is invalid: ' . implode(', ', $fields) . '.');
    }

    public static function forField(string $field, ErrorCode $code, string $message): self
    {
        return new self([new FieldError($field, $code, $message)]);
    }
}
