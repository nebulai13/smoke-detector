<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Mail;

use JsonException;
use SensitiveParameter;
use SmokeDetector\Application\Mail\MailValuesCipher;
use SmokeDetector\Application\Mail\MailValuesUnreadable;
use SmokeDetector\Config\Secret;
use SodiumException;

/**
 * Encrypts the values of a queued mail with libsodium (HLD §4.5 "Mail"):
 *
 *     sealed = base64( nonce . sodium_crypto_secretbox( JSON of the values, nonce, K ) )
 *
 * The nonce is 24 random bytes, new for every mail. K is derived from the configured jwt.secret
 * with HKDF-SHA256 and a fixed purpose label. It is therefore another key than the one that signs
 * the access tokens and than the one of the refresh token secrets (HmacRefreshSecrets), and needs
 * no setting of its own.
 *
 * What follows from that: whoever changes jwt.secret makes the mails that are waiting at that
 * moment unreadable. The sender drops those mails at once, without a try: waiting does not bring
 * the old key back. Their links can be asked for again ("send the link again", a new reset request).
 *
 * secretbox also authenticates: a text that was changed, or made with another key, does not open.
 */
final readonly class SodiumMailValuesCipher implements MailValuesCipher
{
    /** Changing it makes every waiting mail unreadable. */
    private const string PURPOSE = 'smoke-detector mail outbox values v1';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        /** The jwt.secret of the settings. Only the key derived from it is used here. */
        private Secret $secret,
    ) {
    }

    public function seal(#[SensitiveParameter] array $values): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        // An object also when there are no values, so that what open() reads is always a table.
        $json = json_encode($values === [] ? new \stdClass() : $values, self::JSON_FLAGS);

        return base64_encode($nonce . sodium_crypto_secretbox($json, $nonce, $this->key()));
    }

    public function open(string $sealed): array
    {
        $bytes = base64_decode($sealed, true);
        // Shorter than a nonce and the authentication tag: nothing secretbox could have made.
        if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new MailValuesUnreadable();
        }

        try {
            $json = sodium_crypto_secretbox_open(
                substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $this->key(),
            );
            $decoded = $json === false ? null : json_decode($json, false, 2, self::JSON_FLAGS);
        } catch (SodiumException | JsonException) {
            throw new MailValuesUnreadable();
        }
        if (!$decoded instanceof \stdClass) {
            throw new MailValuesUnreadable();
        }

        $values = [];
        foreach (get_object_vars($decoded) as $name => $value) {
            if (!is_string($value)) {
                throw new MailValuesUnreadable();
            }
            $values[(string) $name] = $value;
        }

        return $values;
    }

    private function key(): string
    {
        return hash_hkdf('sha256', $this->secret->reveal(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::PURPOSE);
    }
}
