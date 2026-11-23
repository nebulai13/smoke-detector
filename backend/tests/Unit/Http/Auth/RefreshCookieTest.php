<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http\Auth;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Http\Auth\RefreshCookie;

/**
 * The cookie that carries the web app's refresh token (HLD §4.5, NFR-06).
 */
final class RefreshCookieTest extends TestCase
{
    private const string TOKEN = 'q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng.Zm9vYmFyYmF6cXV4LV8xMjM0NTY3ODkwYWJjZGVmZ2hpams';

    public function test_NFR_06_the_cookie_is_httponly_secure_samesite_strict_and_limited_to_the_auth_path(): void
    {
        $response = RefreshCookie::set(new Response(200), self::TOKEN);

        self::assertSame(
            ['refresh_token=' . self::TOKEN . '; Max-Age=5184000; Path=/api/v1/auth; Secure; HttpOnly; SameSite=Strict'],
            $response->getHeader('Set-Cookie'),
        );
    }

    public function test_NFR_06_the_cookie_lives_as_long_as_the_refresh_token_and_is_for_this_host_only(): void
    {
        $cookie = RefreshCookie::set(new Response(200), self::TOKEN)->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('Max-Age=' . 60 * 24 * 3600 . ';', $cookie);
        // No Domain attribute: the cookie is not sent to subdomains.
        self::assertStringNotContainsStringIgnoringCase('domain', $cookie);
    }

    public function test_NFR_06_clearing_overwrites_the_same_cookie_with_nothing_and_expires_it(): void
    {
        $response = RefreshCookie::clear(new Response(204));

        self::assertSame(
            ['refresh_token=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/api/v1/auth; Secure; HttpOnly; SameSite=Strict'],
            $response->getHeader('Set-Cookie'),
        );
        self::assertSame(RefreshCookie::CLEARED, $response->getHeaderLine('Set-Cookie'));
    }

    public function test_setting_the_cookie_keeps_the_rest_of_the_response(): void
    {
        $response = RefreshCookie::set((new Response(200))->withHeader('Content-Type', 'application/json'), self::TOKEN);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * @return iterable<string, array{list<string>, string|null}>
     */
    public static function cookieHeaders(): iterable
    {
        $token = self::TOKEN;

        yield 'no Cookie header' => [[], null];
        yield 'the cookie alone' => [["refresh_token={$token}"], $token];
        yield 'among other cookies' => [["theme=dark; refresh_token={$token}; lang=de"], $token];
        yield 'without the optional space' => [["theme=dark;refresh_token={$token};lang=de"], $token];
        yield 'only other cookies' => [['theme=dark; lang=de'], null];
        yield 'another cookie whose name ends the same' => [["x_refresh_token={$token}"], null];
        yield 'another cookie whose name starts the same' => [["refresh_token2={$token}"], null];
        yield 'the name in another case' => [["Refresh_Token={$token}"], null];
        yield 'empty value' => [['refresh_token='], null];
        yield 'a value that cannot be a token' => [['refresh_token=a b'], null];
        yield 'a quoted value' => [["refresh_token=\"{$token}\""], null];
        yield 'a value with a percent escape' => [['refresh_token=abc%3D'], null];
        yield 'a value that is too long' => [['refresh_token=' . str_repeat('a', 100) . '.' . str_repeat('b', 100)], null];
        yield 'a value of the maximum length' => [['refresh_token=' . str_repeat('a', 100) . '.' . str_repeat('b', 99)], str_repeat('a', 100) . '.' . str_repeat('b', 99)];
        yield 'the shortest value' => [['refresh_token=a.b'], 'a.b'];
        // A token is "<key>.<secret>": anything of another build is not ours.
        yield 'a value without a dot' => [['refresh_token=' . str_repeat('a', 86)], null];
        yield 'a value with two dots' => [['refresh_token=a.b.c'], null];
        yield 'a value without a key' => [['refresh_token=.b'], null];
        yield 'a value without a secret' => [['refresh_token=a.'], null];
        // "=" is not a base64url character. The pair is split at the first "=" only.
        yield 'a value with "=" in it' => [['refresh_token=abc=.def'], null];
        yield 'a value that ends with "="' => [["refresh_token={$token}="], null];
        yield 'twice with the same value' => [["refresh_token={$token}; refresh_token={$token}"], $token];
        // Somebody planted a second cookie (from a subdomain, say). We cannot know which one is ours.
        yield 'twice with different values' => [["refresh_token={$token}; refresh_token=planted"], null];
        yield 'twice with different values, other order' => [["refresh_token=planted; refresh_token={$token}"], null];
        yield 'in two Cookie headers with different values' => [["refresh_token={$token}", 'refresh_token=planted'], null];
        yield 'a pair without "="' => [["refresh_token; theme=dark"], null];
    }

    /**
     * @param list<string> $headers
     */
    #[DataProvider('cookieHeaders')]
    public function test_NFR_06_the_token_is_read_from_the_cookie_only_when_it_is_unambiguous(array $headers, ?string $expected): void
    {
        $request = new ServerRequest('POST', '/api/v1/auth/refresh');
        foreach ($headers as $header) {
            $request = $request->withAddedHeader('Cookie', $header);
        }

        self::assertSame($expected, RefreshCookie::read($request));
    }

    public function test_NFR_06_a_request_is_known_to_have_brought_the_cookie_even_when_its_value_is_unusable(): void
    {
        $with = static fn (string ...$headers): ServerRequest => array_reduce(
            $headers,
            static fn (ServerRequest $request, string $header): ServerRequest => $request->withAddedHeader('Cookie', $header),
            new ServerRequest('POST', '/api/v1/auth/logout'),
        );

        self::assertFalse(RefreshCookie::isPresented($with()));
        self::assertFalse(RefreshCookie::isPresented($with('theme=dark; x_refresh_token=abc; refresh_token2=abc')));
        self::assertFalse(RefreshCookie::isPresented($with('refresh_token')));
        self::assertTrue(RefreshCookie::isPresented($with('refresh_token=' . self::TOKEN)));
        self::assertTrue(RefreshCookie::isPresented($with('theme=dark;refresh_token=')));
        self::assertTrue(RefreshCookie::isPresented($with('refresh_token=a b')));
        self::assertTrue(RefreshCookie::isPresented($with('refresh_token=one; refresh_token=two')));
        self::assertTrue(RefreshCookie::isPresented($with('theme=dark', 'refresh_token=abc')));
    }
}
