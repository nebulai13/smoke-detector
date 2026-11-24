<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Nudge\Nudge;
use SmokeDetector\Application\Nudge\Nudges;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The nudges in the `nudges` table (HLD §4.3, §4.14): one row per sender, receiver and day of the
 * sender, never changed.
 */
final readonly class PdoNudges implements Nudges
{
    private const string SELECT = 'SELECT from_user_id, to_user_id, `day`, created_at, delivered FROM nudges';

    /** Oldest first; the same time is ordered by sender and receiver, so the order never depends on chance. */
    private const string ORDER = ' ORDER BY created_at, from_user_id, to_user_id';

    public function __construct(private Sql $sql)
    {
    }

    public function find(UserId $from, UserId $to, LocalDate $day): ?Nudge
    {
        return $this->nudgesOf($this->sql->rows(
            self::SELECT . ' WHERE from_user_id = ? AND to_user_id = ? AND `day` = ?',
            [$from->value, $to->value, $day->value],
        ))[0] ?? null;
    }

    public function add(Nudge $nudge): bool
    {
        // INSERT IGNORE would also hide other errors. A key that is there already updates nothing,
        // so the first nudge stays, and the count of changed rows tells whether it was stored now.
        return $this->sql->execute(
            'INSERT INTO nudges (from_user_id, to_user_id, `day`, created_at, delivered) VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE created_at = created_at',
            [$nudge->from->value, $nudge->to->value, $nudge->day->value, Row::sqlTime($nudge->createdAt), (int) $nudge->delivered],
        ) === 1;
    }

    public function deliveredTo(UserId $to, DateTimeImmutable $since): array
    {
        return $this->nudgesOf($this->sql->rows(
            self::SELECT . ' WHERE to_user_id = ? AND delivered = 1 AND created_at >= ?' . self::ORDER,
            [$to->value, Row::sqlTime($since)],
        ));
    }

    public function sentBy(UserId $from): array
    {
        return $this->nudgesOf($this->sql->rows(self::SELECT . ' WHERE from_user_id = ?' . self::ORDER, [$from->value]));
    }

    public function receivedBy(UserId $to): array
    {
        return $this->nudgesOf($this->sql->rows(self::SELECT . ' WHERE to_user_id = ?' . self::ORDER, [$to->value]));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Nudge>
     */
    private function nudgesOf(array $rows): array
    {
        return array_map(static function (array $columns): Nudge {
            $row = new Row($columns);

            return new Nudge(
                UserId::fromString($row->string('from_user_id')),
                UserId::fromString($row->string('to_user_id')),
                LocalDate::fromString($row->string('day')),
                $row->time('created_at'),
                $row->int('delivered') === 1,
            );
        }, $rows);
    }
}
