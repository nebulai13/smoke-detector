<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Me;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Settings\ShowSettings;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Me\SettingsResponder;

/**
 * GET /me/settings (operation getMySettings, FR-GAME-09).
 */
final readonly class ShowSettingsAction
{
    public function __construct(
        private ShowSettings $showSettings,
        private SettingsResponder $settings,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->settings->settings($this->showSettings->of(AuthenticatedUser::id($request))));
    }
}
