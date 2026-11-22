<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\RateLimit;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\RateLimit\RateLimitKeyHasher;
use SmokeDetector\Config\Secret;
use SmokeDetector\Infrastructure\RateLimit\HmacRateLimitKeyHasher;

/**
 * The hash a rate-limit key is made of (HLD §4.3 "rate_limits", NFR-05): HMAC-SHA256 with a key that
 * is derived from `jwt.secret` by HKDF-SHA256 with the label "smoke-detector rate limit keys v1".
 */
final class HmacRateLimitKeyHasherTest extends TestCase
{
    private const string SECRET = 'a-secret-of-at-least-thirty-two-bytes-0123456789';
    private const string OTHER_SECRET = 'another-secret-of-thirty-two-bytes-and-more-9876';

    public function test_NFR_05_the_hash_is_an_hmac_with_a_key_derived_from_the_secret_under_its_own_label(): void
    {
        $hasher = new HmacRateLimitKeyHasher(new Secret(self::SECRET));

        self::assertInstanceOf(RateLimitKeyHasher::class, $hasher);
        self::assertSame(
            bin2hex(hash_hmac('sha256', 'anna@example.org', hash_hkdf('sha256', self::SECRET, 32, 'smoke-detector rate limit keys v1'), true)),
            bin2hex($hasher->hash('anna@example.org')),
        );
    }

    public function test_NFR_05_the_hash_is_32_bytes_and_the_same_every_time(): void
    {
        $hasher = new HmacRateLimitKeyHasher(new Secret(self::SECRET));

        self::assertSame(32, strlen($hasher->hash('203.0.113.7')));
        self::assertSame(32, strlen($hasher->hash('')));
        self::assertSame(32, strlen($hasher->hash(str_repeat('x', 5000))));
        self::assertSame(bin2hex($hasher->hash('203.0.113.7')), bin2hex((new HmacRateLimitKeyHasher(new Secret(self::SECRET)))->hash('203.0.113.7')));
        self::assertNotSame(bin2hex($hasher->hash('203.0.113.7')), bin2hex($hasher->hash('203.0.113.8')));
    }

    public function test_NFR_05_without_the_secret_the_hash_of_an_address_cannot_be_worked_out(): void
    {
        $hash = bin2hex((new HmacRateLimitKeyHasher(new Secret(self::SECRET)))->hash('anna@example.org'));

        // Not the plain hash, which anybody can compute for an address they want to look up.
        self::assertNotSame(hash('sha256', 'anna@example.org'), $hash);
        // Another secret gives another hash: changing jwt.secret starts every counter again.
        self::assertNotSame(bin2hex((new HmacRateLimitKeyHasher(new Secret(self::OTHER_SECRET)))->hash('anna@example.org')), $hash);
    }

    public function test_NFR_05_the_key_is_not_one_that_is_used_for_anything_else(): void
    {
        $hash = bin2hex((new HmacRateLimitKeyHasher(new Secret(self::SECRET)))->hash('anna@example.org'));

        // Not the secret itself (that signs the access tokens), and not the keys derived from it
        // for the refresh tokens and for the mail outbox.
        self::assertNotSame(hash_hmac('sha256', 'anna@example.org', self::SECRET), $hash);
        foreach (['smoke-detector refresh token secret v1', 'smoke-detector mail outbox values v1', ''] as $label) {
            self::assertNotSame(hash_hmac('sha256', 'anna@example.org', hash_hkdf('sha256', self::SECRET, 32, $label)), $hash);
        }
    }
}
