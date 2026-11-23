<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support;

use SmokeDetector\Application\RateLimit\RateLimitKeyHasher;
use SmokeDetector\Config\Secret;
use SmokeDetector\Infrastructure\RateLimit\HmacRateLimitKeyHasher;

/**
 * The key a rate-limit counter is stored under (HLD §4.3 "rate_limits"), worked out here a second
 * time and independently of RateLimiter, so that a test can name the row it expects.
 *
 * A key holds no address in plain text and no plain hash of one: each part is HMAC-SHA256 with a
 * key that is derived from `jwt.secret` (HKDF-SHA256, with a label of its own). Whoever reads the
 * table, a backup of it for example, cannot test whether a given address is in it.
 *
 * The secret is the one of the test run (phpunit.xml), which is also what the application under
 * test is started with in the HTTP tests.
 */
final class RateLimitKey
{
    public const string LABEL = 'smoke-detector rate limit keys v1';

    /**
     * @param string      $rule    The name of the rule: "login.address", "mail.client", …
     * @param string      $subject Who is limited: an email address, a client address, a user id.
     * @param string|null $from    For a rule about a pair: the client address the subject is tried from.
     */
    public static function of(string $rule, string $subject, ?string $from = null): string
    {
        $place = $from === null ? '' : rtrim(strtr(base64_encode(self::raw($from)), '+/', '-_'), '=') . ':';

        return $rule . ':' . $place . self::subjectHash($subject);
    }

    /**
     * The last part of every key of a subject, in hexadecimal: what account deletion looks for.
     */
    public static function subjectHash(string $subject): string
    {
        return bin2hex(self::raw($subject));
    }

    /**
     * The hasher of the application, with the secret of the test run. For unit tests that build a
     * RateLimiter themselves.
     */
    public static function hasher(): RateLimitKeyHasher
    {
        return new HmacRateLimitKeyHasher(new Secret(self::secret()));
    }

    private static function raw(string $value): string
    {
        return hash_hmac('sha256', $value, hash_hkdf('sha256', self::secret(), 32, self::LABEL), true);
    }

    private static function secret(): string
    {
        $secret = getenv('JWT_SECRET');
        \assert(is_string($secret) && $secret !== '', 'The test run has no JWT_SECRET (phpunit.xml).');

        return $secret;
    }
}
