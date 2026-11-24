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
 * The `nudges` table in the data export, member nudgesReceived (schema ExportedNudge): who nudged the
 * user, when, and whether it was delivered, oldest first (HLD §4.14 "Export and deletion"). Also the
 * nudges that were not delivered: they are stored about the user all the same.
 */
final readonly class NudgesReceivedExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        return array_map(
            static fn (Nudge $nudge): array => [
                'userId' => $nudge->from->value,
                'createdAt' => ExportFormat::time($nudge->createdAt),
                'delivered' => $nudge->delivered,
            ],
            (new PdoNudges($this->sql))->receivedBy($userId),
        );
    }
}
