<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `plans` table in the data export (schema ExportedPlan). A user has one plan or none.
 */
final readonly class PlanExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): ?array
    {
        $columns = $this->sql->row(
            'SELECT goal_mode, target, taper_speed, default_product, start_date, rules_version, last_session_before '
            . 'FROM plans WHERE user_id = ?',
            [$userId->value],
        );
        if ($columns === null) {
            return null;
        }
        $row = new Row($columns);

        $plan = [
            'goalMode' => $row->string('goal_mode'),
            'target' => $row->int('target'),
            'taperSpeed' => $row->string('taper_speed'),
            'defaultProduct' => $row->string('default_product'),
            'startDate' => $row->string('start_date'),
            'rulesVersion' => $row->string('rules_version'),
        ];
        // Absent, not null, when the user named no day (schema ExportedPlan).
        $lastSessionBefore = $row->nullableString('last_session_before');
        if ($lastSessionBefore !== null) {
            $plan['lastSessionBefore'] = $lastSessionBefore;
        }

        return $plan;
    }
}
