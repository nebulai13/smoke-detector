<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\RateLimit\RateLimit;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Account\Consent;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Auth\AuthLimits;
use SmokeDetector\Application\Auth\DeviceLabel;
use SmokeDetector\Application\Auth\TokenHash;
use SmokeDetector\Application\Auth\TokenPurpose;

/**
 * The small values of the auth design (HLD §4.5).
 */
final class AuthValuesTest extends TestCase
{
    public function test_NFR_05_a_token_is_stored_as_its_sha256_hash(): void
    {
        self::assertSame(hash('sha256', 'a-token'), TokenHash::of('a-token'));
        // The well-known test vector of SHA-256.
        self::assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', TokenHash::of('abc'));
        self::assertSame(64, strlen(TokenHash::of('')));
        self::assertNotSame(TokenHash::of('a-token'), TokenHash::of('a-tokeN'));
    }

    public function test_FR_ACC_03_a_confirmation_link_lives_24_hours(): void
    {
        self::assertSame(24 * 3600, TokenPurpose::VerifyEmail->lifetimeSeconds());
        self::assertSame('verify_email', TokenPurpose::VerifyEmail->value);
    }

    public function test_FR_ACC_05_a_reset_link_lives_1_hour(): void
    {
        self::assertSame(3600, TokenPurpose::ResetPassword->lifetimeSeconds());
        self::assertSame('reset_password', TokenPurpose::ResetPassword->value);
    }

    public function test_NFR_05_failed_logins_are_limited_per_client_per_pair_of_address_and_client_and_per_address(): void
    {
        // The guesser is locked, not the account: 5 per 15 minutes for one address from one client.
        // Far more for a client over all addresses (a shared network) and for an address over all
        // clients (the owner must not be locked out by a stranger).
        self::assertEquals(new RateLimit('login.client', 30, 900), AuthLimits::loginFailuresPerClient());
        self::assertEquals(new RateLimit('login.pair', 5, 900), AuthLimits::loginFailuresPerPair());
        self::assertEquals(new RateLimit('login.address', 50, 3600), AuthLimits::loginFailuresPerAddress());
    }

    public function test_NFR_05_mail_sending_requests_are_limited_per_client_per_pair_and_per_address(): void
    {
        self::assertEquals(new RateLimit('mail.client', 5, 3600), AuthLimits::mailsPerClient());
        self::assertEquals(new RateLimit('mail.pair', 3, 3600), AuthLimits::mailsPerPair());
        // Over this one nothing is refused: the mail is left out (see MailBudget).
        self::assertEquals(new RateLimit('mail.address', 10, 3600), AuthLimits::mailsPerAddress());
    }

    public function test_NFR_05_a_login_causes_confirmation_mails_under_limits_of_its_own_5_per_hour_per_client_and_1_per_email(): void
    {
        // Their own counters, not those of registration, resend and reset request: those answer
        // 429, and must not move with something only an unconfirmed registration's login does.
        self::assertEquals(new RateLimit('login.mail.client', 5, 3600), AuthLimits::confirmationMailsByLoginPerClient());
        self::assertEquals(new RateLimit('login.mail.email', 1, 3600), AuthLimits::confirmationMailsByLoginPerEmail());
    }

    public function test_a_device_label_has_1_to_100_characters(): void
    {
        self::assertSame('A', DeviceLabel::fromInput('A')->value);
        self::assertSame('Annas iPhone 📱', DeviceLabel::fromInput('  Annas iPhone 📱 ')->value);
        self::assertSame('👩🏽‍🚀', DeviceLabel::fromInput('👩🏽‍🚀')->value);
        self::assertSame(str_repeat('ä', 100), DeviceLabel::fromInput(str_repeat('ä', 100))->value);
        self::assertSame(100, DeviceLabel::MAX_LENGTH);
    }

    /**
     * @return iterable<string, array{string, ErrorCode, string}>
     */
    public static function invalidDeviceLabels(): iterable
    {
        $length = 'Must be 1 to 100 characters long.';
        $characters = 'Must not contain control characters, invisible characters or line breaks.';

        yield 'empty' => ['', ErrorCode::TooShort, $length];
        yield 'only spaces' => ['   ', ErrorCode::TooShort, $length];
        yield '101 characters' => [str_repeat('a', 101), ErrorCode::TooLong, $length];
        yield 'line break' => ["Phone\nX", ErrorCode::InvalidFormat, $characters];
        yield 'trailing line break' => ["Phone\n", ErrorCode::InvalidFormat, $characters];
        yield 'tab' => ["Phone\tX", ErrorCode::InvalidFormat, $characters];
        yield 'NUL' => ["Phone\0", ErrorCode::InvalidFormat, $characters];
        yield 'line separator' => ["Phone\u{2028}X", ErrorCode::InvalidFormat, $characters];
        yield 'paragraph separator' => ["Phone\u{2029}X", ErrorCode::InvalidFormat, $characters];
        yield 'invalid UTF-8' => ["Phone\xFF", ErrorCode::InvalidFormat, $characters];
        // The label is shown back in the list of sessions: the same rule as for a display name.
        yield 'right-to-left override' => ["Phone\u{202E}X", ErrorCode::InvalidFormat, $characters];
        yield 'isolate' => ["Phone\u{2067}X", ErrorCode::InvalidFormat, $characters];
        yield 'zero-width space' => ["Pho\u{200B}ne", ErrorCode::InvalidFormat, $characters];
        yield 'nothing to see' => ["\u{3164}\u{2800}", ErrorCode::TooShort, 'Must have at least 1 visible character.'];
    }

    #[DataProvider('invalidDeviceLabels')]
    public function test_a_device_label_outside_the_rules_is_rejected(string $input, ErrorCode $code, string $message): void
    {
        try {
            DeviceLabel::fromInput($input);
            self::fail('Expected the label to be rejected.');
        } catch (InvalidValue $rejected) {
            self::assertSame([$code, $message], [$rejected->errorCode, $rejected->getMessage()]);
        }
    }

    public function test_FR_ACC_02_a_consent_names_what_was_agreed_to_in_which_version_and_when(): void
    {
        $at = new DateTimeImmutable('2026-11-17 12:00:00 UTC');
        $consent = new Consent(ConsentType::Art9, '2026-11-17.v1_a', $at);

        self::assertSame(ConsentType::Art9, $consent->type);
        self::assertSame('2026-11-17.v1_a', $consent->version);
        self::assertSame($at, $consent->grantedAt);
        self::assertSame(['age18', 'art9', 'privacy'], array_map(static fn (ConsentType $type): string => $type->value, ConsentType::cases()));
        self::assertSame(str_repeat('1', 32), (new Consent(ConsentType::Age18, str_repeat('1', 32), $at))->version);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'empty' => [''];
        yield '33 characters' => [str_repeat('1', 33)];
        yield 'with space' => ['v 1'];
        yield 'starting with a dot' => ['.1'];
        yield 'with a line break' => ["v1\n"];
        yield 'with a slash' => ['v1/2'];
    }

    #[DataProvider('invalidVersions')]
    public function test_FR_ACC_02_a_consent_without_a_proper_version_cannot_be_recorded(string $version): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Consent(ConsentType::Privacy, $version, new DateTimeImmutable('2026-11-17 12:00:00 UTC'));
    }
}
