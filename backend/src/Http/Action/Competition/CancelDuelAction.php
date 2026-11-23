<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Competition;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Competition\DuelInvitations;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\DuelInput;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /duels/cancel (operation cancelDuel): the challenger takes an open proposal back.
 */
final readonly class CancelDuelAction
{
    public function __construct(
        private DuelInvitations $invitations,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $duelId = DuelInput::reference($request);

        $this->invitations->cancel($userId, $duelId);

        return $this->responder->noContent();
    }
}
