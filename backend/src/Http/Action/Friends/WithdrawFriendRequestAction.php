<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Friends;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Friends\FriendRequests;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\UserReference;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /friends/requests/withdraw (operation withdrawFriendRequest): `userId` is the user who was asked.
 */
final readonly class WithdrawFriendRequestAction
{
    public function __construct(
        private FriendRequests $friendRequests,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $other = UserReference::read($request);

        $this->friendRequests->withdraw($userId, $other);

        return $this->responder->noContent();
    }
}
