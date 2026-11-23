<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Friends;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Friends\FriendList;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Friends\FriendsResponder;
use SmokeDetector\Http\JsonResponder;

/**
 * GET /friends (operation listFriends, FR-FRND-02, FR-FRND-03): friends and open requests.
 */
final readonly class ListFriendsAction
{
    public function __construct(
        private FriendList $friendList,
        private FriendsResponder $friends,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->friends->overview($this->friendList->show(AuthenticatedUser::id($request))));
    }
}
