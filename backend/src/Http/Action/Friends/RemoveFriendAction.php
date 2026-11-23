<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Friends;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Friends\FriendList;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\UserReference;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /friends/remove (operation removeFriend, FR-FRND-02): ends the friendship for both.
 */
final readonly class RemoveFriendAction
{
    public function __construct(
        private FriendList $friendList,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $other = UserReference::read($request);

        $this->friendList->remove($userId, $other);

        return $this->responder->noContent();
    }
}
