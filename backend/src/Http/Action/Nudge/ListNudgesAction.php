<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Nudge;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Nudge\ShowNudges;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Nudge\NudgesResponder;

/**
 * GET /nudges (operation listNudges, FR-SOC-03): who nudged the user today.
 */
final readonly class ListNudgesAction
{
    public function __construct(
        private ShowNudges $showNudges,
        private NudgesResponder $nudges,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->nudges->nudges($this->showNudges->of(AuthenticatedUser::id($request))));
    }
}
