<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\RateLimit;

use SensitiveParameter;
use SmokeDetector\Application\RateLimit\RateLimitKeyHasher;
use SmokeDetector\Config\Secret;

/**
 * The hashes of rate-limit keys by HMAC (HLD §4.3 "rate_limits", NFR-05):
 *
 *     hash(value) = HMAC-SHA256( K, value )
 *
 * K is derived from the configured jwt.secret with HKDF-SHA256 and a fixed purpose label. It is
 * therefore another key than the one that signs the access tokens and than the ones of the refresh
 * token secrets (HmacRefreshSecrets) and of the mail outbox (SodiumMailValuesCipher), and needs no
 * setting of its own. Whoever changes jwt.secret starts every counter again at zero: no stored key
 * matches a key made under the new one. The old rows go with the cleanup.
 */
final readonly class HmacRateLimitKeyHasher implements RateLimitKeyHasher
{
    /** Changing it changes every key: all counters start again. */
    private const string PURPOSE = 'smoke-detector rate limit keys v1';

    private const int KEY_BYTES = 32;

    public function __construct(
        /** The jwt.secret of the settings. Only the key derived from it is used here. */
        private Secret $secret,
    ) {
    }

    public function hash(#[SensitiveParameter] string $value): string
    {
        $key = hash_hkdf('sha256', $this->secret->reveal(), self::KEY_BYTES, self::PURPOSE);

        return hash_hmac('sha256', $value, $key, true);
    }
}
