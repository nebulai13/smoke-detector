<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Account;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Account\EmailAddress;

/**
 * The address an account is found by (HLD §4.3 "email (unique, lower-cased)").
 */
final class EmailAddressTest extends TestCase
{
    public function test_an_address_is_compared_without_case_and_surrounding_space(): void
    {
        $address = EmailAddress::fromInput("  Anna.Schmidt+Test@Example.ORG \t\r\n");

        self::assertSame('anna.schmidt+test@example.org', $address->value);
        self::assertTrue($address->equals(EmailAddress::fromInput('anna.schmidt+test@example.org')));
        self::assertFalse($address->equals(EmailAddress::fromInput('anna.schmidt@example.org')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'only space' => ['   '];
        yield 'no at sign' => ['anna.example.org'];
        yield 'no local part' => ['@example.org'];
        yield 'no domain' => ['anna@'];
        yield 'domain without a dot' => ['anna@localhost'];
        yield 'two at signs' => ['anna@bob@example.org'];
        yield 'space inside' => ['an na@example.org'];
        yield 'quoted local part' => ['"anna"@example.org'];
        yield 'line break inside (header injection)' => ["anna@example.org\nBcc: eve@example.org"];
        yield 'display name form' => ['Anna <anna@example.org>'];
        // Not ASCII: two spellings of one address could otherwise become two accounts, or one.
        yield 'non-ASCII local part' => ['änna@example.org'];
        yield 'non-ASCII domain' => ['anna@exämple.org'];
        yield 'local part of 65 characters' => [str_repeat('a', 65) . '@example.org'];
        yield '255 characters' => [str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 58) . '.org'];
        yield 'domain label starting with a hyphen' => ['anna@-example.org'];
        yield 'empty domain label' => ['anna@example..org'];
        yield 'leading dot in the local part' => ['.anna@example.org'];
        yield 'no-break space around it is not trimmed away' => ["\u{00A0}anna@example.org"];
    }

    #[DataProvider('invalidAddresses')]
    public function test_FR_ACC_01_something_that_is_not_an_email_address_is_rejected(string $input): void
    {
        try {
            EmailAddress::fromInput($input);
            self::fail('Expected the address to be rejected.');
        } catch (InvalidValue $rejected) {
            self::assertSame([ErrorCode::InvalidFormat, 'Must be an email address.'], [$rejected->errorCode, $rejected->getMessage()]);
        }
    }

    public function test_the_longest_possible_address_is_accepted(): void
    {
        $address = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 57) . '.org';
        self::assertSame(254, strlen($address));

        self::assertSame($address, EmailAddress::fromInput($address)->value);
    }

    public function test_usual_special_characters_are_accepted(): void
    {
        self::assertSame("o'neil_x-y+tag@sub.example-site.co.uk", EmailAddress::fromInput("O'Neil_x-y+tag@sub.example-site.co.uk")->value);
        self::assertSame('a@b.de', EmailAddress::fromInput('a@b.de')->value);
    }

    public function test_the_message_never_repeats_the_input(): void
    {
        try {
            EmailAddress::fromInput('secret-typo-at-example.org');
            self::fail('Expected an exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('secret-typo', $exception->getMessage());
        }
    }
}
