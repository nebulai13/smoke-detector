<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use InvalidArgumentException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Feed\FeedEvents;
use SmokeDetector\Application\Feed\FeedEventType;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The entries of the activity feed in the `feed_events` table (HLD §4.3): written once, never
 * changed, in the order of `position`.
 */
final readonly class PdoFeedEvents implements FeedEvents
{
    private const string SELECT = 'SELECT id, user_id, type, detail, other_user_id, created_at FROM feed_events';

    public function __construct(private Sql $sql)
    {
    }

    public function append(FeedEvent $event): void
    {
        $this->sql->execute(
            'INSERT INTO feed_events (id, user_id, type, detail, other_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$event->id, $event->userId->value, $event->type->value, $event->detail, $event->otherUserId?->value, Row::sqlTime($event->createdAt)],
        );
    }

    public function find(string $id): ?FeedEvent
    {
        return $this->eventsOf($this->sql->rows(self::SELECT . ' WHERE id = ?', [$id]))[0] ?? null;
    }

    public function newestOf(array $userIds, int $limit, int $skip = 0): array
    {
        if ($limit < 0 || $skip < 0) {
            // A mistake of the caller: never handed to the database.
            throw new InvalidArgumentException('A limit and a number to skip cannot be negative.');
        }
        if ($userIds === []) {
            return [];
        }

        // By the order of writing: the times of one cron run are the same.
        return $this->eventsOf($this->sql->rows(
            self::SELECT . ' WHERE user_id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ') ORDER BY position DESC LIMIT ? OFFSET ?',
            [...array_map(static fn (UserId $userId): string => $userId->value, $userIds), $limit, $skip],
        ));
    }

    public function ofUser(UserId $userId): array
    {
        return $this->eventsOf($this->sql->rows(self::SELECT . ' WHERE user_id = ? ORDER BY position', [$userId->value]));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<FeedEvent> A row of a type the code does not know (written by a later version) is left out.
     */
    private function eventsOf(array $rows): array
    {
        $events = [];
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $type = FeedEventType::tryFrom($row->string('type'));
            if ($type === null) {
                continue;
            }
            $other = $row->nullableString('other_user_id');
            $events[] = new FeedEvent(
                $row->string('id'),
                UserId::fromString($row->string('user_id')),
                $type,
                $row->nullableString('detail'),
                $other === null ? null : UserId::fromString($other),
                $row->time('created_at'),
            );
        }

        return $events;
    }
}
