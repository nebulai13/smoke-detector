<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `timezone_history` table in the data export, oldest entry first (schema ExportedZoneChange).
 */
final readonly class TimeZoneHistoryExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $entries = [];
        $rows = $this->sql->rows(
            'SELECT valid_from, timezone FROM timezone_history WHERE user_id = ? ORDER BY valid_from',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $entries[] = [
                'validFrom' => $row->string('valid_from'),
                'timeZone' => $row->string('timezone'),
            ];
        }

        return $entries;
    }
}
