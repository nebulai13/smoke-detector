<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use SmokeDetector\Application\Auth\Sessions;
use SmokeDetector\Http\Routes;

/**
 * The cookie that carries the refresh token of the web app (HLD §4.5, NFR-06).
 *
 * - HttpOnly: scripts cannot read it, so a cross-site scripting hole cannot steal the session.
 * - Secure: never sent over plain http.
 * - SameSite=Strict: never sent with a request that another site started, so no other site can
 *   make the browser refresh or log out (no CSRF token needed).
 * - Path=/api/v1/auth: sent to the auth endpoints only, not with every API call.
 * - No Domain: for this host only, not for subdomains.
 */
final readonly class RefreshCookie
{
    public const string NAME = 'refresh_token';

    private const string ATTRIBUTES = '; Path=' . Routes::BASE_PATH . '/auth; Secure; HttpOnly; SameSite=Strict';

    /** The Set-Cookie value that removes the cookie from the browser. */
    public const string CLEARED = self::NAME . '=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT' . self::ATTRIBUTES;

    /** What a token can look like ("<key>.<secret>", both base64url). Anything else in the cookie is not ours. */
    private const string VALUE = '/\A(?=.{3,200}\z)[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D';

    public static function set(ResponseInterface $response, #[SensitiveParameter] string $refreshToken): ResponseInterface
    {
        return $response->withHeader(
            'Set-Cookie',
            self::NAME . '=' . $refreshToken . '; Max-Age=' . Sessions::REFRESH_TOKEN_LIFETIME_SECONDS . self::ATTRIBUTES,
        );
    }

    public static function clear(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Set-Cookie', self::CLEARED);
    }

    /**
     * The refresh token of the request's cookie, or null when there is none to rely on.
     *
     * Read from the Cookie header itself. When the cookie is there more than once with different
     * values, somebody else has set one of them (a cookie can be planted from a subdomain), and
     * nothing says which is ours: then none is used.
     */
    public static function read(ServerRequestInterface $request): ?string
    {
        $values = array_values(array_unique(self::values($request)));
        if (count($values) !== 1 || preg_match(self::VALUE, $values[0]) !== 1) {
            return null;
        }

        return $values[0];
    }

    /**
     * Whether the request brought the cookie at all, usable or not. Only then is the browser ever
     * told to drop it: the browser does not send the cookie with a request another site started, so
     * such a request cannot be used to log the user out of this browser.
     */
    public static function isPresented(ServerRequestInterface $request): bool
    {
        return self::values($request) !== [];
    }

    /**
     * @return list<string> The value of every "refresh_token=…" pair of the Cookie header(s).
     */
    private static function values(ServerRequestInterface $request): array
    {
        $values = [];
        foreach ($request->getHeader('Cookie') as $header) {
            foreach (explode(';', $header) as $pair) {
                $parts = explode('=', trim($pair, ' '), 2);
                if (count($parts) === 2 && $parts[0] === self::NAME) {
                    $values[] = $parts[1];
                }
            }
        }

        return $values;
    }
}
