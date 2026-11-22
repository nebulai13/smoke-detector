<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `smoke_logs` table in the data export, in the order the sessions were smoked (schema
 * ExportedSmokeLog). Deleted logs too: they are stored, and say when they were deleted.
 */
final readonly class SmokeLogsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $logs = [];
        $rows = $this->sql->rows(
            'SELECT id, smoked_at, local_date, product_type, `count`, created_at, updated_at, deleted_at '
            . 'FROM smoke_logs WHERE user_id = ? ORDER BY smoked_at, id',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $logs[] = [
                'id' => $row->string('id'),
                'smokedAt' => ExportFormat::time($row->time('smoked_at')),
                'localDate' => $row->string('local_date'),
                'productType' => $row->string('product_type'),
                'count' => $row->int('count'),
                'createdAt' => ExportFormat::time($row->time('created_at')),
                'updatedAt' => ExportFormat::time($row->time('updated_at')),
                'deletedAt' => ExportFormat::nullableTime($row->nullableTime('deleted_at')),
            ];
        }

        return $logs;
    }
}
