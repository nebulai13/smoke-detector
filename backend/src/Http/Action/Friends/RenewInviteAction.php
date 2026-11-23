<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Friends;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Friends\Invites;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Friends\FriendsResponder;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /me/invite (operation renewInvite): a new invite code; the old one leads nowhere at once.
 * The request has no body.
 */
final readonly class RenewInviteAction
{
    public function __construct(
        private Invites $invites,
        private FriendsResponder $friends,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->friends->invite($this->invites->renew(AuthenticatedUser::id($request))));
    }
}
