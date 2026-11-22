<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use SmokeDetector\Application\Auth\Session;
use SmokeDetector\Http\JsonResponder;

/**
 * Answers login, the confirmation of a registration and refresh with the token pair (schema
 * Session), in one of two ways (HLD §4.5):
 *
 * - body mode (native apps): the refresh token is a member of the JSON body, and no cookie is set.
 * - cookie mode (web app): the refresh token is in the HttpOnly cookie, and never in the body, where
 *   a script could read it.
 */
final readonly class SessionResponder
{
    public function __construct(private JsonResponder $responder)
    {
    }

    public function inBody(Session $session): ResponseInterface
    {
        return $this->responder->json(self::accessToken($session) + ['refreshToken' => $session->refreshToken]);
    }

    public function inCookie(Session $session): ResponseInterface
    {
        return RefreshCookie::set($this->responder->json(self::accessToken($session)), $session->refreshToken);
    }

    /**
     * @return array{accessToken: string, tokenType: string, expiresIn: int}
     */
    private static function accessToken(Session $session): array
    {
        return [
            'accessToken' => $session->accessToken->token,
            'tokenType' => 'Bearer',
            'expiresIn' => $session->accessToken->expiresInSeconds,
        ];
    }
}
