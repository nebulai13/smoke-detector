<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Logging;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Day\ShowDashboard;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Logging\LoggingResponder;

/**
 * GET /me/dashboard (operation getDashboard, FR-LOG-05, FR-GAME-06).
 */
final readonly class ShowDashboardAction
{
    public function __construct(
        private ShowDashboard $showDashboard,
        private LoggingResponder $logging,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $dashboard = $this->showDashboard->execute(AuthenticatedUser::id($request));

        return $this->responder->json([
            'today' => $this->logging->day($dashboard->today),
            'progress' => $this->logging->progress($dashboard->progress),
        ]);
    }
}
