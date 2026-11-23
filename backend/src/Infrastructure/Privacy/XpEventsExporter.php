<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `xp_events` table in the data export, by day (schema ExportedXpEvent). The id is not
 * selected: it means nothing outside the database.
 */
final readonly class XpEventsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $events = [];
        $rows = $this->sql->rows(
            'SELECT local_date, source, amount, detail, created_at FROM xp_events WHERE user_id = ? ORDER BY local_date, id',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $events[] = [
                'date' => $row->string('local_date'),
                'source' => $row->string('source'),
                'amount' => $row->int('amount'),
                'detail' => $row->nullableInt('detail'),
                'createdAt' => ExportFormat::time($row->time('created_at')),
            ];
        }

        return $events;
    }
}
