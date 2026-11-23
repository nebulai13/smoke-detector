<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Friends;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Friends\FriendRequests;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Friends\FriendsResponder;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\RequestBody;

/**
 * POST /friends/requests (operation sendFriendRequest, FR-FRND-01): asks the owner of an invite
 * code to be friends.
 */
final readonly class SendFriendRequestAction
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

        $fields = new Fields(RequestBody::of($request), ['code']);
        $code = $fields->make('code', InviteCode::fromInput(...));
        if ($code === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        return $this->responder->json($this->friends->sent($this->friendRequests->send($userId, $code)));
    }
}
