<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\DayEventsJson;
use UnexpectedValueException;

/**
 * The `day_summaries` table in the data export, oldest day first (schema ExportedDay). What
 * finalization writes is null for a day that is not final yet.
 */
final readonly class DaysExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $days = [];
        $rows = $this->sql->rows(
            'SELECT local_date, `count`, checked_in_at, daily_limit, xp, events, freeze_used, finalized_at, rules_version '
            . 'FROM day_summaries WHERE user_id = ? ORDER BY local_date',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $events = $row->nullableString('events');
            $days[] = [
                'date' => $row->string('local_date'),
                'count' => $row->int('count'),
                'checkedInAt' => ExportFormat::nullableTime($row->nullableTime('checked_in_at')),
                'dailyLimit' => $row->nullableInt('daily_limit'),
                'xp' => $row->nullableInt('xp'),
                // Read through the same shape the repository writes, so a damaged row is not passed on.
                'events' => $events === null ? null : DayEventsJson::toArrays(DayEventsJson::decode($events)),
                'freezeUsed' => self::nullableBool($row->nullableInt('freeze_used')),
                'finalizedAt' => ExportFormat::nullableTime($row->nullableTime('finalized_at')),
                'rulesVersion' => $row->nullableString('rules_version'),
            ];
        }

        return $days;
    }

    private static function nullableBool(?int $value): ?bool
    {
        return match ($value) {
            null => null,
            0 => false,
            1 => true,
            default => throw new UnexpectedValueException('Column "freeze_used" is not a boolean.'),
        };
    }
}
