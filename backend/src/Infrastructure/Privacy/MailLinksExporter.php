<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `one_time_tokens` table in the data export (schema ExportedMailLink): that a confirmation
 * or reset link was mailed, until when it works and whether it was used. The token hash is not
 * selected at all, so it cannot get into the document.
 */
final readonly class MailLinksExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $links = [];
        $rows = $this->sql->rows(
            'SELECT purpose, expires_at, used_at FROM one_time_tokens WHERE user_id = ? ORDER BY expires_at, id',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $links[] = [
                'purpose' => $row->string('purpose'),
                'expiresAt' => ExportFormat::time($row->time('expires_at')),
                'usedAt' => ExportFormat::nullableTime($row->nullableTime('used_at')),
            ];
        }

        return $links;
    }
}
