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
 * GET /me/invite (operation showInvite, FR-FRND-01): the invite code and the link that carries it.
 * The first call makes the code.
 */
final readonly class ShowInviteAction
{
    public function __construct(
        private Invites $invites,
        private FriendsResponder $friends,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->friends->invite($this->invites->codeOf(AuthenticatedUser::id($request))));
    }
}
