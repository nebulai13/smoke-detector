<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Application\Day\XpLedger;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The XP ledger in the `xp_events` table. It only ever inserts and reads.
 */
final readonly class PdoXpLedger implements XpLedger
{
    public function __construct(private Sql $sql)
    {
    }

    public function append(XpEvent $event): void
    {
        $this->sql->execute(
            'INSERT INTO xp_events (id, user_id, local_date, source, amount, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $event->id,
                $event->userId->value,
                $event->date->value,
                $event->source->value,
                $event->amount,
                $event->detail,
                Row::sqlTime($event->createdAt),
            ],
        );
    }

    public function ofUser(UserId $userId): array
    {
        $events = [];
        $rows = $this->sql->rows(
            'SELECT id, user_id, local_date, source, amount, detail, created_at FROM xp_events WHERE user_id = ? ORDER BY local_date, id',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $events[] = new XpEvent(
                $row->string('id'),
                UserId::fromString($row->string('user_id')),
                LocalDate::fromString($row->string('local_date')),
                XpSource::from($row->string('source')),
                $row->int('amount'),
                $row->nullableInt('detail'),
                $row->time('created_at'),
            );
        }

        return $events;
    }
}
