<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Competition;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Competition\ShowDuels;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Competition\DuelsResponder;
use SmokeDetector\Http\JsonResponder;

/**
 * GET /duels (operation listDuels, FR-COMP-01): the newest duels of the user.
 */
final readonly class ListDuelsAction
{
    public function __construct(
        private ShowDuels $showDuels,
        private DuelsResponder $duels,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->duels->list($this->showDuels->list(AuthenticatedUser::id($request))));
    }
}
