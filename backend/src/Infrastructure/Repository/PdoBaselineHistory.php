<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use PDOException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\BaselineAlreadyRecorded;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\BaselineHistory;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The baseline history in the `baseline_history` table. It only ever inserts and reads.
 */
final readonly class PdoBaselineHistory implements BaselineHistory
{
    private const string SELECT = 'SELECT user_id, valid_from, value, source, w_mean, m_mean, w_days, m_days, rules_version '
        . 'FROM baseline_history';

    /** MariaDB's error number for a value that is already in a unique index. */
    private const int DUPLICATE_ENTRY = 1062;

    public function __construct(private Sql $sql)
    {
    }

    public function append(BaselineEntry $entry): void
    {
        try {
            $this->sql->execute(
                'INSERT INTO baseline_history (user_id, valid_from, value, source, w_mean, m_mean, w_days, m_days, '
                . 'rules_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $entry->userId->value,
                    $entry->validFrom->value,
                    // As text with one decimal place, built from the integer: no float on the way.
                    intdiv($entry->value->tenths, 10) . '.' . $entry->value->tenths % 10,
                    $entry->source->value,
                    $entry->weekMean,
                    $entry->monthMean,
                    $entry->weekDays,
                    $entry->monthDays,
                    $entry->rulesVersion,
                ],
            );
        } catch (PDOException $failure) {
            // The primary key is (user_id, valid_from), the only unique index of the table.
            if (($failure->errorInfo[1] ?? null) === self::DUPLICATE_ENTRY) {
                throw new BaselineAlreadyRecorded();
            }

            throw $failure;
        }
    }

    public function latest(UserId $userId): ?BaselineEntry
    {
        $columns = $this->sql->row(self::SELECT . ' WHERE user_id = ? ORDER BY valid_from DESC LIMIT 1', [$userId->value]);

        return $columns === null ? null : self::entry($columns);
    }

    public function starting(UserId $userId): ?BaselineEntry
    {
        $columns = $this->sql->row(
            self::SELECT . ' WHERE user_id = ? AND source = ? ORDER BY valid_from LIMIT 1',
            [$userId->value, BaselineSource::Onboarding->value],
        );

        return $columns === null ? null : self::entry($columns);
    }

    public function ofUser(UserId $userId): array
    {
        return array_map(
            self::entry(...),
            $this->sql->rows(self::SELECT . ' WHERE user_id = ? ORDER BY valid_from', [$userId->value]),
        );
    }

    /**
     * @param array<string, mixed> $columns
     */
    private static function entry(array $columns): BaselineEntry
    {
        $row = new Row($columns);

        return new BaselineEntry(
            UserId::fromString($row->string('user_id')),
            LocalDate::fromString($row->string('valid_from')),
            Baseline::fromTenths($row->tenths('value')),
            BaselineSource::from($row->string('source')),
            $row->nullableFloat('w_mean'),
            $row->nullableFloat('m_mean'),
            $row->nullableInt('w_days'),
            $row->nullableInt('m_days'),
            $row->string('rules_version'),
        );
    }
}
