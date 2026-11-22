<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;

/**
 * An email address in the one spelling the application stores and compares (HLD §4.3: lower-cased).
 *
 * Only ASCII addresses are accepted. With that, lower-casing is the whole normalisation: there is no
 * second spelling (other Unicode form, other case rule) under which the same mailbox could become a
 * second account, or two mailboxes one account.
 */
final readonly class EmailAddress
{
    /** RFC 5321: what fits into the path of an SMTP command. */
    private const int MAX_LENGTH = 254;

    // At most 64 characters before the "@" (RFC 5321), then dot-separated atoms @ dot-separated host
    // labels, at least two labels. No quoted local part, no address literal, no comment: nobody
    // needs them, and each is a way to smuggle characters.
    private const string PATTERN = '/\A(?=[^@]{1,64}@)'
        . '[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*'
        . '@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/';

    private function __construct(public string $value)
    {
    }

    /**
     * @param string $input As the user typed it: case and surrounding white space do not matter.
     *
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(string $input): self
    {
        $value = strtolower(trim($input));

        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidValue(ErrorCode::InvalidFormat, 'Must be an email address.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
