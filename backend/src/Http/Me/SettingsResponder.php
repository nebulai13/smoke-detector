<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Me;

use SmokeDetector\Application\Settings\Settings;

/**
 * Writes the settings as the schema Settings of api/openapi.yaml: the answer of GET and PUT /me/settings.
 */
final readonly class SettingsResponder
{
    /**
     * @return array<string, mixed>
     */
    public function settings(Settings $settings): array
    {
        return ['milestoneSharing' => $settings->milestoneSharing->value];
    }
}
