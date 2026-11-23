<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Friends;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Friends\Blocking;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\UserReference;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /friends/block (operation blockUser, FR-FRND-02): blocks a friend, or somebody with an open request.
 */
final readonly class BlockUserAction
{
    public function __construct(
        private Blocking $blocking,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $other = UserReference::read($request);

        $this->blocking->block($userId, $other);

        return $this->responder->noContent();
    }
}
