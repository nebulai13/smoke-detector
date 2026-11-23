<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoUserSettings;

/**
 * The `user_settings` table in the data export (schema Settings), HLD §4.11. Always there: a user
 * who never chose has the defaults, and those count as much as a choice.
 */
final readonly class SettingsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        return ['milestoneSharing' => (new PdoUserSettings($this->sql))->find($userId)->milestoneSharing->value];
    }
}
