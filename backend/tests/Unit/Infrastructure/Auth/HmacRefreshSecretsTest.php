<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Auth;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SmokeDetector\Config\Secret;
use SmokeDetector\Infrastructure\Auth\HmacRefreshSecrets;

/**
 * Where the secret part of a refresh token comes from (HLD §4.5 "Login and tokens"): from the
 * session's key and the generation of the token, so that the server can make the same token again
 * without storing it.
 */
final class HmacRefreshSecretsTest extends TestCase
{
    private const string JWT_SECRET = 'test-only-jwt-secret-0123456789abcdef0123456789abcdef';
    private const string SESSION_KEY = 'q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng';

    public function test_NFR_05_a_secret_is_the_hmac_sha256_of_session_key_and_generation_under_a_key_derived_from_the_jwt_secret(): void
    {
        $secrets = new HmacRefreshSecrets(new Secret(self::JWT_SECRET));

        // K = HKDF-SHA256 of the configured secret with a fixed purpose label, 32 bytes.
        $key = hash_hkdf('sha256', self::JWT_SECRET, 32, 'smoke-detector refresh token secret v1');
        $expected = static fn (int $generation): string => rtrim(strtr(
            base64_encode(hash_hmac('sha256', self::SESSION_KEY . '.' . $generation, $key, true)),
            '+/',
            '-_',
        ), '=');

        self::assertSame($expected(0), $secrets->secret(self::SESSION_KEY, 0));
        self::assertSame($expected(1), $secrets->secret(self::SESSION_KEY, 1));
        self::assertSame($expected(4_000_000_000), $secrets->secret(self::SESSION_KEY, 4_000_000_000));
        // Written down once, so that a change of the derivation (which ends every session) cannot
        // happen unnoticed.
        self::assertSame('m88i75rPf8tO1wBuI8xen99y0q1UPIxqK4BRXLsAFGc', $secrets->secret(self::SESSION_KEY, 0));
    }

    public function test_NFR_05_a_secret_is_32_bytes_as_base64url_like_every_other_token(): void
    {
        $secret = (new HmacRefreshSecrets(new Secret(self::JWT_SECRET)))->secret(self::SESSION_KEY, 3);

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $secret);
        $bytes = base64_decode(strtr($secret, '-_', '+/'), true);
        self::assertIsString($bytes);
        self::assertSame(32, strlen($bytes));
    }

    public function test_NFR_05_the_same_key_and_generation_always_give_the_same_secret(): void
    {
        // That is the point: the answer to a repeated refresh is made again, not looked up.
        $first = new HmacRefreshSecrets(new Secret(self::JWT_SECRET));
        $second = new HmacRefreshSecrets(new Secret(self::JWT_SECRET));

        self::assertSame($first->secret(self::SESSION_KEY, 5), $first->secret(self::SESSION_KEY, 5));
        self::assertSame($first->secret(self::SESSION_KEY, 5), $second->secret(self::SESSION_KEY, 5));
    }

    public function test_NFR_05_every_generation_every_session_and_every_installation_has_other_secrets(): void
    {
        $secrets = new HmacRefreshSecrets(new Secret(self::JWT_SECRET));
        $elsewhere = new HmacRefreshSecrets(new Secret(strrev(self::JWT_SECRET)));

        $all = [];
        for ($generation = 0; $generation < 200; $generation++) {
            $all[] = $secrets->secret(self::SESSION_KEY, $generation);
            $all[] = $secrets->secret(strrev(self::SESSION_KEY), $generation);
            $all[] = $elsewhere->secret(self::SESSION_KEY, $generation);
        }

        self::assertCount(600, array_unique($all));
        // Key and generation cannot run into each other: "ab" with 12 is not "ab1" with 2.
        self::assertNotSame($secrets->secret('ab', 12), $secrets->secret('ab1', 2));
    }

    public function test_NFR_05_the_key_is_not_the_one_that_signs_the_access_tokens(): void
    {
        // Derived for this purpose alone. A secret made with the JWT secret itself as the key would
        // tie the two uses of the setting together.
        $secrets = new HmacRefreshSecrets(new Secret(self::JWT_SECRET));
        $withTheJwtSecretItself = rtrim(strtr(
            base64_encode(hash_hmac('sha256', self::SESSION_KEY . '.0', self::JWT_SECRET, true)),
            '+/',
            '-_',
        ), '=');

        self::assertNotSame($withTheJwtSecretItself, $secrets->secret(self::SESSION_KEY, 0));
    }

    public function test_NFR_05_neither_the_configured_secret_nor_the_derived_key_shows_in_a_dump(): void
    {
        $secrets = new HmacRefreshSecrets(new Secret(self::JWT_SECRET));
        $derived = hash_hkdf('sha256', self::JWT_SECRET, 32, 'smoke-detector refresh token secret v1');

        $dump = print_r($secrets, true) . var_export((new ReflectionClass($secrets))->getProperties(), true);

        self::assertStringNotContainsString(self::JWT_SECRET, $dump);
        self::assertStringNotContainsString($derived, $dump);
    }
}
