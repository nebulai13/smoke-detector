<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Settings;

use SmokeDetector\Application\Account\UserId;

/**
 * GET /me/settings (SRS FR-GAME-09, HLD §4.11): the user's settings, the defaults before the first choice.
 */
final readonly class ShowSettings
{
    public function __construct(private UserSettings $settings)
    {
    }

    public function of(UserId $userId): Settings
    {
        return $this->settings->find($userId);
    }
}
