<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `user_progress` table in the data export (schema ExportedProgress). A user has one row, from
 * the onboarding on, or none.
 *
 * The two due instants are not selected: they are the schedule of the cron job.
 */
final readonly class ProgressExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): ?array
    {
        $columns = $this->sql->row(
            'SELECT check_in_current, check_in_longest, under_limit_current, under_limit_longest, freezes_held, xp_total, level, next_day '
            . 'FROM user_progress WHERE user_id = ?',
            [$userId->value],
        );
        if ($columns === null) {
            return null;
        }
        $row = new Row($columns);

        return [
            'checkInStreak' => $row->int('check_in_current'),
            'longestCheckInStreak' => $row->int('check_in_longest'),
            'underLimitStreak' => $row->int('under_limit_current'),
            'longestUnderLimitStreak' => $row->int('under_limit_longest'),
            'freezesHeld' => $row->int('freezes_held'),
            'xpTotal' => $row->int('xp_total'),
            'level' => $row->int('level'),
            'nextDayToFinalize' => $row->string('next_day'),
        ];
    }
}
