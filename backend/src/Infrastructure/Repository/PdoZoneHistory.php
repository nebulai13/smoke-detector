<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Time\ZoneChange;
use SmokeDetector\Application\Time\ZoneHistory;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The history of the time zones in the `timezone_history` table.
 */
final readonly class PdoZoneHistory implements ZoneHistory
{
    public function __construct(private Sql $sql)
    {
    }

    public function record(UserId $userId, ZoneChange $change): void
    {
        // One statement, so two requests that record the same day at the same moment cannot both insert.
        $this->sql->execute(
            'INSERT INTO timezone_history (user_id, valid_from, timezone) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE timezone = VALUES(timezone)',
            [$userId->value, $change->validFrom->value, $change->zone->name],
        );
    }

    public function dropAfter(UserId $userId, LocalDate $date): void
    {
        $this->sql->execute(
            'DELETE FROM timezone_history WHERE user_id = ? AND valid_from > ?',
            [$userId->value, $date->value],
        );
    }

    public function ofUser(UserId $userId): array
    {
        $changes = [];
        $rows = $this->sql->rows(
            'SELECT valid_from, timezone FROM timezone_history WHERE user_id = ? ORDER BY valid_from',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $changes[] = new ZoneChange(
                LocalDate::fromString($row->string('valid_from')),
                // Stored from a TimeZone, so it is a name the server knows (or an older one it maps).
                TimeZone::fromInput($row->string('timezone')),
            );
        }

        return $changes;
    }
}
