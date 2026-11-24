<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Nudge;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Nudge\SendNudge;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\UserReference;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /friends/nudge (operation nudgeFriend, FR-SOC-03): reminds a friend to check in. The answer is
 * empty whether the nudge is delivered or not: it must not tell whether the friend has checked in.
 */
final readonly class NudgeFriendAction
{
    public function __construct(
        private SendNudge $sendNudge,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $friend = UserReference::read($request);

        $this->sendNudge->execute($userId, $friend);

        return $this->responder->noContent();
    }
}
