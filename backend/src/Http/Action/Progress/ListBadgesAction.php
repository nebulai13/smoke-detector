<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Progress;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Badge\ShowBadges;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Progress\ProgressResponder;

/**
 * GET /me/badges (operation listBadges, FR-GAME-08).
 */
final readonly class ListBadgesAction
{
    public function __construct(
        private ShowBadges $showBadges,
        private ProgressResponder $progress,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->progress->badges($this->showBadges->of(AuthenticatedUser::id($request))));
    }
}
