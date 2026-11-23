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
 * POST /friends/unblock (operation unblockUser): lifts a block of the user.
 */
final readonly class UnblockUserAction
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

        $this->blocking->unblock($userId, $other);

        return $this->responder->noContent();
    }
}
