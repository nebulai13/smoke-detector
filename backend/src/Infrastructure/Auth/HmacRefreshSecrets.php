<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Auth;

use SensitiveParameter;
use SmokeDetector\Application\Auth\RefreshSecrets;
use SmokeDetector\Config\Secret;

/**
 * Refresh token secrets by HMAC (HLD §4.5 "Login and tokens"):
 *
 *     secret(n) = base64url( HMAC-SHA256( K, <session key> "." <n> ) )
 *
 * K is derived from the configured jwt.secret with HKDF-SHA256 and a fixed purpose label. It is
 * therefore another key than the one that signs the access tokens, and needs no setting of its own.
 * What follows from that: whoever changes jwt.secret ends every session, not only the access
 * tokens that are out, because no stored token hash matches a token made under the new key.
 *
 * The session key is 43 characters of base64url and holds no dot, so the key and the number cannot
 * run into each other. Without the session key (which is only in the tokens, the database has its
 * hash) K alone makes no token, and without K a token of the session says nothing about the next.
 */
final readonly class HmacRefreshSecrets implements RefreshSecrets
{
    /** Changing it changes every secret: all sessions end. */
    private const string PURPOSE = 'smoke-detector refresh token secret v1';

    private const int KEY_BYTES = 32;

    public function __construct(
        /** The jwt.secret of the settings. Only the key derived from it is used here. */
        private Secret $secret,
    ) {
    }

    public function secret(#[SensitiveParameter] string $sessionKey, int $generation): string
    {
        $key = hash_hkdf('sha256', $this->secret->reveal(), self::KEY_BYTES, self::PURPOSE);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $sessionKey . '.' . $generation, $key, true)), '+/', '-_'), '=');
    }
}
