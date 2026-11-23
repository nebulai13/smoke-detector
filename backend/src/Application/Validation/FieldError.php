<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Validation;

use InvalidArgumentException;

/**
 * What is wrong with one field of the input.
 */
final readonly class FieldError
{
    public function __construct(
        /** Name of the request field, with a dot for nested fields ("consents.age18"). Empty for the body as a whole. */
        public string $field,
        /** What clients decide and translate by. There is no field error without one. */
        public ErrorCode $code,
        /** For the developer of a client, in English. Must not repeat the value the user sent. */
        public string $message,
    ) {
        if ($message === '') {
            throw new InvalidArgumentException('A field error needs a message.');
        }
    }
}
