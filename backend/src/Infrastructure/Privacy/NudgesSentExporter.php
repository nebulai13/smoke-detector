<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Nudge\Nudge;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoNudges;

/**
 * The `nudges` table in the data export, member nudgesSent (schema ExportedNudge): whom the user
 * nudged and when, oldest first (HLD §4.14 "Export and deletion"). Without `delivered`: whether a
 * friend had checked in is the friend's to know, not the sender's.
 */
final readonly class NudgesSentExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        return array_map(
            static fn (Nudge $nudge): array => ['userId' => $nudge->to->value, 'createdAt' => ExportFormat::time($nudge->createdAt)],
            (new PdoNudges($this->sql))->sentBy($userId),
        );
    }
}
