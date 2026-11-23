<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `baseline_history` table in the data export, oldest entry first (schema ExportedBaseline).
 */
final readonly class BaselineHistoryExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $entries = [];
        $rows = $this->sql->rows(
            'SELECT valid_from, value, source, w_mean, m_mean, w_days, m_days, rules_version '
            . 'FROM baseline_history WHERE user_id = ? ORDER BY valid_from',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $entries[] = [
                'validFrom' => $row->string('valid_from'),
                // Always a float (12.0, not 12), so the document has one type for the member.
                'value' => Baseline::fromTenths($row->tenths('value'))->toFloat(),
                'source' => $row->string('source'),
                'weekMean' => $row->nullableFloat('w_mean'),
                'monthMean' => $row->nullableFloat('m_mean'),
                'weekDays' => $row->nullableInt('w_days'),
                'monthDays' => $row->nullableInt('m_days'),
                'rulesVersion' => $row->string('rules_version'),
            ];
        }

        return $entries;
    }
}
