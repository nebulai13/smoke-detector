<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Auth\InvalidRefreshToken;
use SmokeDetector\Application\Auth\RefreshSession;
use SmokeDetector\Http\Auth\PresentedRefreshToken;
use SmokeDetector\Http\Auth\RefreshCookie;
use SmokeDetector\Http\Auth\SessionResponder;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;

/**
 * POST /auth/refresh (operation refreshSession): a new token pair for a refresh token, delivered
 * the way the old one came.
 */
final readonly class RefreshAction
{
    public function __construct(
        private RefreshSession $refreshSession,
        private SessionResponder $sessions,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $presented = PresentedRefreshToken::of($request);

        try {
            if ($presented->token === null) {
                throw new InvalidRefreshToken();
            }
            $session = $this->refreshSession->execute($presented->token);
        } catch (InvalidRefreshToken) {
            throw new ProblemException($this->refused($presented));
        }

        return $presented->inBody ? $this->sessions->inBody($session) : $this->sessions->inCookie($session);
    }

    /**
     * A browser that sent the cookie is also told to drop it: it holds nothing usable any more.
     */
    private function refused(PresentedRefreshToken $presented): Problem
    {
        $problem = Problem::invalidRefreshToken();
        if (!$presented->cookieToClear) {
            return $problem;
        }

        return new Problem(
            $problem->type,
            $problem->title,
            $problem->status,
            $problem->detail,
            $problem->extensions,
            $problem->headers + ['Set-Cookie' => RefreshCookie::CLEARED],
        );
    }
}
