<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Application\Recovery\ReachedMilestone;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoMilestoneRecords;

/**
 * The `user_milestones` table in the data export (schema ReachedHealthMilestone): the health
 * milestones the user has reached for good, with the moment of each, in the order of the cases
 * (HLD §4.11).
 */
final readonly class HealthMilestonesExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        return array_map(
            static fn (ReachedMilestone $reached): array => ['key' => $reached->milestone->value, 'reachedAt' => ExportFormat::time($reached->reachedAt)],
            (new PdoMilestoneRecords($this->sql))->ofUser($userId),
        );
    }
}
