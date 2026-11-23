<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Input;

use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Http\RequestBody;

/**
 * The body of PUT /me/settings (schema Settings): {"milestoneSharing": "from_two_weeks"}. Every
 * member is required, so the answer is always the whole of the settings.
 */
final readonly class SettingsInput
{
    /**
     * @throws ValidationFailed With every error at once. A value that is not one of MilestoneSharing
     *                          is "invalid_value".
     */
    public static function read(ServerRequestInterface $request): Settings
    {
        $fields = new Fields(RequestBody::of($request), ['milestoneSharing']);
        $milestoneSharing = $fields->enum('milestoneSharing', MilestoneSharing::class);
        if ($milestoneSharing === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        return new Settings($milestoneSharing);
    }
}
