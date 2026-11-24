<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Day\DayResult;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;
use UnexpectedValueException;

/**
 * The days in the `day_summaries` table. A day is final exactly when `finalized_at` is set; the
 * other columns finalization writes are NULL before it.
 */
final readonly class PdoDayRecords implements DayRecords
{
    private const string SELECT = 'SELECT user_id, local_date, `count`, checked_in_at, daily_limit, xp, events, freeze_used, '
        . 'finalized_at, rules_version FROM day_summaries';

    public function __construct(private Sql $sql)
    {
    }

    public function find(UserId $userId, LocalDate $date): ?DayRecord
    {
        $columns = $this->sql->row(self::SELECT . ' WHERE user_id = ? AND local_date = ?', [$userId->value, $date->value]);

        return $columns === null ? null : self::day($columns);
    }

    public function save(DayRecord $day): void
    {
        $result = $day->result;
        // One statement, so two requests that save the same day at the same moment cannot both insert.
        $this->sql->execute(
            'INSERT INTO day_summaries (user_id, local_date, `count`, checked_in_at, daily_limit, xp, events, freeze_used, '
            . 'finalized_at, rules_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `count` = VALUES(`count`), checked_in_at = VALUES(checked_in_at), '
            . 'daily_limit = VALUES(daily_limit), xp = VALUES(xp), events = VALUES(events), freeze_used = VALUES(freeze_used), '
            . 'finalized_at = VALUES(finalized_at), rules_version = VALUES(rules_version)',
            [
                $day->userId->value,
                $day->date->value,
                $day->count,
                Row::nullableSqlTime($day->checkedInAt),
                $result?->dailyLimit,
                $result?->xp,
                $result === null ? null : DayEventsJson::encode($result->events),
                $result?->freezeUsed,
                $result === null ? null : Row::sqlTime($result->finalizedAt),
                $result?->rulesVersion,
            ],
        );
    }

    public function between(UserId $userId, LocalDate $from, LocalDate $to): array
    {
        return array_map(
            self::day(...),
            $this->sql->rows(
                self::SELECT . ' WHERE user_id = ? AND local_date >= ? AND local_date <= ? ORDER BY local_date',
                [$userId->value, $from->value, $to->value],
            ),
        );
    }

    public function isCheckedInOn(UserId $userId, LocalDate $date): bool
    {
        // One row whatever the answer, and nothing turned into an object: the same work for a day
        // with a check-in, one without, and no day at all.
        $checkedIn = $this->sql->value(
            'SELECT EXISTS(SELECT 1 FROM day_summaries WHERE user_id = ? AND local_date = ? AND checked_in_at IS NOT NULL)',
            [$userId->value, $date->value],
        );

        return (new Row(['checked_in' => $checkedIn]))->int('checked_in') === 1;
    }

    public function lastMissedUntil(UserId $userId, LocalDate $day): ?LocalDate
    {
        $columns = $this->sql->row(
            'SELECT local_date FROM day_summaries WHERE user_id = ? AND local_date <= ? AND checked_in_at IS NULL '
            . 'ORDER BY local_date DESC LIMIT 1',
            [$userId->value, $day->value],
        );

        return $columns === null ? null : LocalDate::fromString((new Row($columns))->string('local_date'));
    }

    /**
     * @param array<string, mixed> $columns
     */
    private static function day(array $columns): DayRecord
    {
        $row = new Row($columns);

        return new DayRecord(
            UserId::fromString($row->string('user_id')),
            LocalDate::fromString($row->string('local_date')),
            $row->int('count'),
            $row->nullableTime('checked_in_at'),
            $row->nullableTime('finalized_at') === null ? null : self::result($row),
        );
    }

    private static function result(Row $row): DayResult
    {
        $freezeUsed = $row->int('freeze_used');
        if ($freezeUsed !== 0 && $freezeUsed !== 1) {
            throw new UnexpectedValueException('Column "freeze_used" is not a boolean.');
        }

        return new DayResult(
            $row->int('daily_limit'),
            $row->int('xp'),
            DayEventsJson::decode($row->string('events')),
            $freezeUsed === 1,
            $row->time('finalized_at'),
            $row->string('rules_version'),
        );
    }
}
