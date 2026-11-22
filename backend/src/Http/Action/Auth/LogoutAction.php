<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Auth\LogOut;
use SmokeDetector\Http\Auth\PresentedRefreshToken;
use SmokeDetector\Http\Auth\RefreshCookie;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /auth/logout (operation logout): ends the session of the presented refresh token, on this
 * device only (FR-ACC-04). Always 204: whoever asks to be logged out is logged out afterwards,
 * whatever the token was worth.
 */
final readonly class LogoutAction
{
    public function __construct(
        private LogOut $logOut,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $presented = PresentedRefreshToken::of($request);

        if ($presented->token !== null) {
            $this->logOut->execute($presented->token);
        }

        $response = $this->responder->noContent();

        // Body mode never touches cookies. A browser that sent the cookie is told to forget it, also
        // when its value was of no use.
        return $presented->cookieToClear ? RefreshCookie::clear($response) : $response;
    }
}
