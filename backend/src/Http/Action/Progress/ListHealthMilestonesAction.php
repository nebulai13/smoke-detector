<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Progress;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Recovery\ShowHealthMilestones;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Progress\ProgressResponder;

/**
 * GET /me/health-milestones (operation listHealthMilestones, FR-GAME-09).
 */
final readonly class ListHealthMilestonesAction
{
    public function __construct(
        private ShowHealthMilestones $showHealthMilestones,
        private ProgressResponder $progress,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->progress->healthMilestones($this->showHealthMilestones->of(AuthenticatedUser::id($request))));
    }
}
