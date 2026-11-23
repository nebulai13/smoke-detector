<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Badge\AwardedBadge;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoBadgeRecords;

/**
 * The `user_badges` table in the data export (schema Badge): the user's badges with the time of
 * each, in the order of the Badge cases (HLD §4.10).
 */
final readonly class BadgesExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        return array_map(
            static fn (AwardedBadge $badge): array => ['key' => $badge->badge->value, 'awardedAt' => ExportFormat::time($badge->awardedAt)],
            (new PdoBadgeRecords($this->sql))->ofUser($userId),
        );
    }
}
