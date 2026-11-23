<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Friends;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Friends\FriendRequests;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Friends\FriendsResponder;
use SmokeDetector\Http\Input\UserReference;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /friends/requests/accept (operation acceptFriendRequest): `userId` is the user who asked.
 * The answer is the new friend.
 */
final readonly class AcceptFriendRequestAction
{
    public function __construct(
        private FriendRequests $friendRequests,
        private FriendsResponder $friends,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $requester = UserReference::read($request);

        return $this->responder->json($this->friends->friend($this->friendRequests->accept($userId, $requester)));
    }
}
