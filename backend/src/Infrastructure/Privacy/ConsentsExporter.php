<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `consents` table in the data export: which text the user agreed to, in which version, when
 * (schema ExportedConsent). These are the records LEG-03 wants auditable.
 */
final readonly class ConsentsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $consents = [];
        $rows = $this->sql->rows(
            'SELECT type, version, granted_at FROM consents WHERE user_id = ? ORDER BY type, version',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $consents[] = [
                'type' => $row->string('type'),
                'version' => $row->string('version'),
                'grantedAt' => ExportFormat::time($row->time('granted_at')),
            ];
        }

        return $consents;
    }
}
