<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Competition;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Competition\DuelInvitations;
use SmokeDetector\Application\Competition\ShowDuels;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Competition\DuelsResponder;
use SmokeDetector\Http\Input\DuelInput;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /duels/accept (operation acceptDuel, FR-COMP-02). The answer is the duel as the one who
 * accepted sees it.
 */
final readonly class AcceptDuelAction
{
    public function __construct(
        private DuelInvitations $invitations,
        private ShowDuels $showDuels,
        private DuelsResponder $duels,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $duelId = DuelInput::reference($request);

        $this->invitations->accept($userId, $duelId);

        return $this->responder->json($this->duels->duel($this->showDuels->show($userId, $duelId)->duel));
    }
}
