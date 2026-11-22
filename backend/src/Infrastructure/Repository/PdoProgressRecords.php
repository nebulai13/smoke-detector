<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Streak\StreakState;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The progress of the users in the `user_progress` table.
 */
final readonly class PdoProgressRecords implements ProgressRecords
{
    public function __construct(private Sql $sql)
    {
    }

    public function find(UserId $userId): ?ProgressRecord
    {
        $columns = $this->sql->row(
            'SELECT user_id, check_in_current, check_in_longest, under_limit_current, under_limit_longest, freezes_held, '
            . 'xp_total, level, next_day, finalize_due_at, baseline_due_at FROM user_progress WHERE user_id = ?',
            [$userId->value],
        );
        if ($columns === null) {
            return null;
        }
        $row = new Row($columns);

        return new ProgressRecord(
            UserId::fromString($row->string('user_id')),
            new UserProgress(
                new StreakState(
                    $row->int('check_in_current'),
                    $row->int('check_in_longest'),
                    $row->int('under_limit_current'),
                    $row->int('under_limit_longest'),
                    $row->int('freezes_held'),
                ),
                $row->int('xp_total'),
            ),
            $row->int('level'),
            LocalDate::fromString($row->string('next_day')),
            $row->time('finalize_due_at'),
            $row->time('baseline_due_at'),
        );
    }

    public function save(ProgressRecord $record): void
    {
        $streaks = $record->progress->streaks;
        $this->sql->execute(
            'INSERT INTO user_progress (user_id, check_in_current, check_in_longest, under_limit_current, under_limit_longest, '
            . 'freezes_held, xp_total, level, next_day, finalize_due_at, baseline_due_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE check_in_current = VALUES(check_in_current), check_in_longest = VALUES(check_in_longest), '
            . 'under_limit_current = VALUES(under_limit_current), under_limit_longest = VALUES(under_limit_longest), '
            . 'freezes_held = VALUES(freezes_held), xp_total = VALUES(xp_total), level = VALUES(level), '
            . 'next_day = VALUES(next_day), finalize_due_at = VALUES(finalize_due_at), baseline_due_at = VALUES(baseline_due_at)',
            [
                $record->userId->value,
                $streaks->checkInCurrent,
                $streaks->checkInLongest,
                $streaks->underLimitCurrent,
                $streaks->underLimitLongest,
                $streaks->freezesHeld,
                $record->progress->totalXp,
                $record->level,
                $record->nextDay->value,
                Row::sqlTime($record->finalizeDueAt),
                Row::sqlTime($record->baselineDueAt),
            ],
        );
    }

    public function due(DateTimeImmutable $now, int $limit): array
    {
        $now = Row::sqlTime($now);
        // Two range reads, one on each index, instead of one OR that could read the whole table.
        $ids = $this->sql->column(
            'SELECT user_id FROM ('
            . 'SELECT user_id, LEAST(finalize_due_at, baseline_due_at) AS due_at FROM user_progress WHERE finalize_due_at <= ? '
            . 'UNION '
            . 'SELECT user_id, LEAST(finalize_due_at, baseline_due_at) AS due_at FROM user_progress WHERE baseline_due_at <= ?'
            . ') AS due ORDER BY due_at, user_id LIMIT ?',
            [$now, $now, $limit],
        );

        return array_map(static fn (mixed $id): UserId => UserId::fromString((new Row(['id' => $id]))->string('id')), $ids);
    }
}
