<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Feed;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Feed\ShowFeed;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Feed\FeedResponder;
use SmokeDetector\Http\JsonResponder;

/**
 * GET /feed (operation showFeed, FR-SOC-01).
 */
final readonly class ShowFeedAction
{
    public function __construct(
        private ShowFeed $showFeed,
        private FeedResponder $feed,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->feed->feed($this->showFeed->show(AuthenticatedUser::id($request))));
    }
}
