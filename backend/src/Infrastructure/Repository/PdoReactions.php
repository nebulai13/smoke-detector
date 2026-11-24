<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Feed\Reaction;
use SmokeDetector\Application\Feed\ReactionKey;
use SmokeDetector\Application\Feed\Reactions;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The reactions to feed entries in the `feed_reactions` table (HLD §4.3, §4.13): one row per entry
 * and user.
 */
final readonly class PdoReactions implements Reactions
{
    private const string SELECT = 'SELECT event_id, user_id, reaction, created_at FROM feed_reactions';

    /** Oldest first; the same time is ordered by entry and user, so the order never depends on chance. */
    private const string ORDER = ' ORDER BY created_at, event_id, user_id';

    public function __construct(private Sql $sql)
    {
    }

    public function find(string $eventId, UserId $userId): ?Reaction
    {
        return $this->reactionsOf($this->sql->rows(self::SELECT . ' WHERE event_id = ? AND user_id = ?', [$eventId, $userId->value]))[0] ?? null;
    }

    public function save(Reaction $reaction): bool
    {
        // One statement: a new row counts 1, a changed one 2 and an unchanged one 0. The time of a
        // row that is there stays.
        return $this->sql->execute(
            'INSERT INTO feed_reactions (event_id, user_id, reaction, created_at) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE reaction = VALUES(reaction)',
            [$reaction->eventId, $reaction->userId->value, $reaction->key->value, Row::sqlTime($reaction->createdAt)],
        ) === 1;
    }

    public function ofEvents(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $byEvent = [];
        $rows = $this->sql->rows(
            self::SELECT . ' WHERE event_id IN (' . implode(', ', array_fill(0, count($eventIds), '?')) . ')' . self::ORDER,
            $eventIds,
        );
        foreach ($this->reactionsOf($rows) as $reaction) {
            $byEvent[$reaction->eventId][] = $reaction;
        }
        ksort($byEvent);

        return $byEvent;
    }

    public function countSentBy(UserId $userId): int
    {
        return (new Row(['sent' => $this->sql->value('SELECT COUNT(*) FROM feed_reactions WHERE user_id = ?', [$userId->value])]))->int('sent');
    }

    public function sentBy(UserId $userId): array
    {
        return $this->reactionsOf($this->sql->rows(self::SELECT . ' WHERE user_id = ?' . self::ORDER, [$userId->value]));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Reaction> A row with a reaction the code does not know (written by a later version) is left out.
     */
    private function reactionsOf(array $rows): array
    {
        $reactions = [];
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $key = ReactionKey::tryFrom($row->string('reaction'));
            if ($key === null) {
                continue;
            }
            $reactions[] = new Reaction($row->string('event_id'), UserId::fromString($row->string('user_id')), $key, $row->time('created_at'));
        }

        return $reactions;
    }
}
