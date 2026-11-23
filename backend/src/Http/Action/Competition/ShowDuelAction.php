<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Competition;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Competition\ShowDuels;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Competition\DuelsResponder;
use SmokeDetector\Http\Input\DuelInput;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /duels/show (operation showDuel, FR-COMP-09): one duel with its days. The id is in the body.
 */
final readonly class ShowDuelAction
{
    public function __construct(
        private ShowDuels $showDuels,
        private DuelsResponder $duels,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $duelId = DuelInput::reference($request);

        return $this->responder->json($this->duels->detail($this->showDuels->show($userId, $duelId)));
    }
}
