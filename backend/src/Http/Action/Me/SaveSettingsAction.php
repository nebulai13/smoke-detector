<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Me;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Settings\SaveSettings;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\SettingsInput;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Me\SettingsResponder;

/**
 * PUT /me/settings (operation saveMySettings, FR-GAME-09): counts at once.
 */
final readonly class SaveSettingsAction
{
    public function __construct(
        private SaveSettings $saveSettings,
        private SettingsResponder $settings,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);

        return $this->responder->json($this->settings->settings($this->saveSettings->execute($userId, SettingsInput::read($request))));
    }
}
