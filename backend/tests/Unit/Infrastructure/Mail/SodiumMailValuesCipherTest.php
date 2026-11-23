<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Mail;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Mail\MailValuesUnreadable;
use SmokeDetector\Config\Secret;
use SmokeDetector\Infrastructure\Mail\SodiumMailValuesCipher;
use SmokeDetector\Tests\Support\SettingsFixture;

/**
 * The encryption of the values of a queued mail (HLD §4.5 "Mail"): the outbox must not hold a
 * link, and with it a one-time token, in plain text.
 */
final class SodiumMailValuesCipherTest extends TestCase
{
    private const array VALUES = [
        'confirmUrl' => 'https://app.example.test/verify-email?token=Zm9vYmFyLXRva2VuLTAxMjM0NTY3ODktQUJDREVGR0hJSktM',
        'other' => 'https://app.example.test/ünï/cödé?a=1&b=2',
    ];

    public function test_NFR_05_what_was_sealed_is_opened_again_exactly(): void
    {
        $cipher = self::cipher();

        self::assertSame(self::VALUES, $cipher->open($cipher->seal(self::VALUES)));
        self::assertSame([], $cipher->open($cipher->seal([])));
    }

    public function test_NFR_05_the_sealed_text_shows_nothing_of_the_values(): void
    {
        $sealed = self::cipher()->seal(self::VALUES);

        // Printable, so it fits into a text column.
        self::assertMatchesRegularExpression('~\A[A-Za-z0-9+/]+={0,2}\z~', $sealed);
        foreach ([$sealed, (string) base64_decode($sealed, true)] as $text) {
            self::assertStringNotContainsString('Zm9vYmFy', $text);
            self::assertStringNotContainsString('verify-email', $text);
            self::assertStringNotContainsString('confirmUrl', $text);
        }
    }

    public function test_NFR_05_every_row_is_sealed_with_a_nonce_of_its_own(): void
    {
        $cipher = self::cipher();

        $first = $cipher->seal(self::VALUES);
        $second = $cipher->seal(self::VALUES);

        // Otherwise two rows with the same link would look the same, and one key stream would be
        // used twice.
        self::assertNotSame($first, $second);
        self::assertNotSame(substr((string) base64_decode($first, true), 0, 24), substr((string) base64_decode($second, true), 0, 24));
        self::assertSame(self::VALUES, $cipher->open($first));
        self::assertSame(self::VALUES, $cipher->open($second));
    }

    public function test_NFR_05_the_key_is_derived_from_the_jwt_secret_for_this_purpose_alone(): void
    {
        $secret = SettingsFixture::JWT_SECRET;
        $sealed = (string) base64_decode(self::cipher()->seal(self::VALUES), true);
        $nonce = substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // sodium_crypto_secretbox under HKDF-SHA256(jwt.secret, "smoke-detector mail outbox values v1").
        $key = hash_hkdf('sha256', $secret, 32, 'smoke-detector mail outbox values v1');
        self::assertSame(json_encode(self::VALUES, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), sodium_crypto_secretbox_open($box, $nonce, $key));

        // Neither the key that signs the access tokens (the secret itself) nor the key of the
        // refresh token secrets opens it.
        self::assertFalse(sodium_crypto_secretbox_open($box, $nonce, substr($secret, 0, 32)));
        self::assertFalse(sodium_crypto_secretbox_open($box, $nonce, hash_hkdf('sha256', $secret, 32)));
        self::assertFalse(sodium_crypto_secretbox_open($box, $nonce, hash_hkdf('sha256', $secret, 32, 'smoke-detector refresh token secret v1')));
    }

    public function test_NFR_05_another_secret_opens_nothing(): void
    {
        $sealed = self::cipher()->seal(self::VALUES);

        // What a changed jwt.secret means for the mails that are still waiting.
        $this->expectException(MailValuesUnreadable::class);

        (new SodiumMailValuesCipher(new Secret('another-jwt-secret-0123456789-abcdefghijklmnopqrstuvwxyz')))->open($sealed);
    }

    public function test_NFR_05_a_changed_text_is_refused_and_the_message_repeats_nothing_of_it(): void
    {
        $cipher = self::cipher();
        $sealed = (string) base64_decode($cipher->seal(self::VALUES), true);
        $last = strlen($sealed) - 1;
        $changed = base64_encode(substr($sealed, 0, $last) . chr(ord($sealed[$last]) ^ 1));

        foreach ([$changed, 'not base64 at all!', '', base64_encode('too short'), base64_encode(str_repeat('x', 24)), 'c2VhbGVk'] as $broken) {
            try {
                $cipher->open($broken);
                self::fail('Expected the text to be refused.');
            } catch (MailValuesUnreadable $refused) {
                self::assertSame('The values of a queued mail cannot be read.', $refused->getMessage());
                self::assertNull($refused->getPrevious());
            }
        }
    }

    public function test_NFR_05_what_opens_but_is_not_a_table_of_texts_is_refused(): void
    {
        $key = hash_hkdf('sha256', SettingsFixture::JWT_SECRET, 32, 'smoke-detector mail outbox values v1');
        $seal = static function (string $json) use ($key): string {
            $nonce = str_repeat("\x07", SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return base64_encode($nonce . sodium_crypto_secretbox($json, $nonce, $key));
        };
        // The helper itself makes what the cipher reads.
        self::assertSame(['a' => 'b'], self::cipher()->open($seal('{"a":"b"}')));

        foreach (['"text"', '["a","b"]', '{"a":1}', '{"a":{"b":"c"}}', '{"a":null}', 'no json', ''] as $json) {
            try {
                self::cipher()->open($seal($json));
                self::fail("Expected {$json} to be refused.");
            } catch (MailValuesUnreadable) {
            }
        }
    }

    private static function cipher(): SodiumMailValuesCipher
    {
        return new SodiumMailValuesCipher(SettingsFixture::settings()->jwtSecret);
    }
}
