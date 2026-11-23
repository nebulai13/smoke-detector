<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Competition;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Competition\ShowXpLeague;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Competition\LeaguesResponder;
use SmokeDetector\Http\JsonResponder;

/**
 * GET /leagues/xp (operation showXpLeague, FR-COMP-07): the user and their friends by the XP of the
 * user's current week.
 */
final readonly class ShowXpLeagueAction
{
    public function __construct(
        private ShowXpLeague $showXpLeague,
        private LeaguesResponder $leagues,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->leagues->xpLeague($this->showXpLeague->show(AuthenticatedUser::id($request))));
    }
}
