<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use InvalidArgumentException;

/**
 * The id of an account: a UUID (version 7 for new ones) in lower case with dashes, which is also
 * how the database returns it.
 */
final readonly class UserId
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) !== 1) {
            throw new InvalidArgumentException('A user id must be a UUID in lower case with dashes.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
