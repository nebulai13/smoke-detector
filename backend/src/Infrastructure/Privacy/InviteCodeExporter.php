<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `invite_codes` table in the data export (schema ExportedInvite). A user has one code, from
 * the first look at it on, or none.
 */
final readonly class InviteCodeExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): ?array
    {
        $columns = $this->sql->row('SELECT code, created_at FROM invite_codes WHERE user_id = ?', [$userId->value]);
        if ($columns === null) {
            return null;
        }
        $row = new Row($columns);

        return [
            'code' => $row->string('code'),
            'createdAt' => ExportFormat::time($row->time('created_at')),
        ];
    }
}
