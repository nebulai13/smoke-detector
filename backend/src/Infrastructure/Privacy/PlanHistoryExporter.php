<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `plan_history` table in the data export, oldest entry first (schema ExportedPlanTerms).
 */
final readonly class PlanHistoryExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $entries = [];
        $rows = $this->sql->rows(
            'SELECT valid_from, goal_mode, target, taper_speed, rules_version FROM plan_history WHERE user_id = ? ORDER BY valid_from',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $entries[] = [
                'validFrom' => $row->string('valid_from'),
                'goalMode' => $row->string('goal_mode'),
                'target' => $row->int('target'),
                'taperSpeed' => $row->string('taper_speed'),
                'rulesVersion' => $row->string('rules_version'),
            ];
        }

        return $entries;
    }
}
