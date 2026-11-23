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
 * POST /duels (operation proposeDuel, FR-COMP-01, FR-COMP-05): challenges a friend. The answer (201)
 * is the duel as the challenger sees it.
 */
final readonly class ProposeDuelAction
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
        [$opponentId, $periodDays] = DuelInput::proposal($request);

        $proposal = $this->invitations->propose($userId, $opponentId, $periodDays);

        return $this->responder->json($this->duels->duel($this->showDuels->show($userId, $proposal->id)->duel), 201);
    }
}
