<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Friends;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Friends\Blocking;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Friends\FriendsResponder;
use SmokeDetector\Http\JsonResponder;

/**
 * GET /friends/blocked (operation listBlockedUsers): whom the user has blocked. Nobody sees who has
 * blocked them.
 */
final readonly class ListBlockedUsersAction
{
    public function __construct(
        private Blocking $blocking,
        private FriendsResponder $friends,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->friends->blocked($this->blocking->blocked(AuthenticatedUser::id($request))));
    }
}
