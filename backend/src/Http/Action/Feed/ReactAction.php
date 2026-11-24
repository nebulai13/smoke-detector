<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Feed;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Feed\React;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Feed\FeedResponder;
use SmokeDetector\Http\Input\ReactionInput;
use SmokeDetector\Http\JsonResponder;

/**
 * POST /feed/reactions (operation reactToFeedEntry, FR-SOC-02): reacts to an entry of a friend. The
 * answer is the reaction as it is stored now, and the XP it earned.
 */
final readonly class ReactAction
{
    public function __construct(
        private React $react,
        private FeedResponder $feed,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        [$entryId, $reaction] = ReactionInput::read($request);

        return $this->responder->json($this->feed->reaction($this->react->execute($userId, $entryId, $reaction)));
    }
}
