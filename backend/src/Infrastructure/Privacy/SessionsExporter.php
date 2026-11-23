<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `refresh_tokens` table in the data export (schema ExportedSession): which devices were
 * logged in and when, one entry per login. The hashes of the key and of the token are not selected
 * at all, so they cannot get into the document.
 */
final readonly class SessionsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $sessions = [];
        $rows = $this->sql->rows(
            'SELECT id, device_label, created_at, rotated_at, expires_at, revoked_at '
            . 'FROM refresh_tokens WHERE user_id = ? ORDER BY created_at, id',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $sessions[] = [
                'session' => $row->string('id'),
                'deviceLabel' => $row->nullableString('device_label'),
                'createdAt' => ExportFormat::time($row->time('created_at')),
                'lastRefreshedAt' => ExportFormat::nullableTime($row->nullableTime('rotated_at')),
                'expiresAt' => ExportFormat::time($row->time('expires_at')),
                'revokedAt' => ExportFormat::nullableTime($row->nullableTime('revoked_at')),
            ];
        }

        return $sessions;
    }
}
