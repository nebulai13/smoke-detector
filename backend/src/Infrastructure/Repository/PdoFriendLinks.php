<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\Friendship;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * Friend requests and friendships in the `friendships` table: one row per pair, the user with the
 * smaller id (as text) in `user_a`.
 *
 * Which id is smaller is decided here, by comparing the strings. MariaDB orders its UUID type in
 * another way (by the time fields first), so no statement compares or sorts the ids.
 */
final readonly class PdoFriendLinks implements FriendLinks
{
    private const string COLUMNS = 'user_a, user_b, requested_by, requested_at, accepted_at';

    public function __construct(private Sql $sql)
    {
    }

    public function between(UserId $a, UserId $b): ?Friendship
    {
        $columns = $this->sql->row(
            'SELECT ' . self::COLUMNS . ' FROM friendships WHERE user_a = ? AND user_b = ?',
            self::pair($a, $b),
        );

        return $columns === null ? null : self::friendship(new Row($columns));
    }

    public function save(Friendship $friendship): void
    {
        $this->sql->execute(
            'INSERT INTO friendships (' . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE requested_by = VALUES(requested_by), requested_at = VALUES(requested_at), accepted_at = VALUES(accepted_at)',
            [
                ...self::pair($friendship->requester, $friendship->recipient),
                $friendship->requester->value,
                Row::sqlTime($friendship->requestedAt),
                Row::nullableSqlTime($friendship->acceptedAt),
            ],
        );
    }

    public function delete(UserId $a, UserId $b): void
    {
        $this->sql->execute('DELETE FROM friendships WHERE user_a = ? AND user_b = ?', self::pair($a, $b));
    }

    public function ofUser(UserId $userId): array
    {
        // Two reads, one on each index, instead of one OR that could read the whole table.
        $rows = $this->sql->rows(
            'SELECT ' . self::COLUMNS . ' FROM friendships WHERE user_a = ? '
            . 'UNION ALL SELECT ' . self::COLUMNS . ' FROM friendships WHERE user_b = ?',
            [$userId->value, $userId->value],
        );
        $links = array_map(static fn (array $columns): Friendship => self::friendship(new Row($columns)), $rows);
        usort(
            $links,
            static fn (Friendship $x, Friendship $y): int => $x->requestedAt <=> $y->requestedAt
                ?: strcmp($x->other($userId)->value, $y->other($userId)->value),
        );

        return $links;
    }

    /**
     * @return array{string, string} The two ids as the table stores them: the smaller one first.
     */
    private static function pair(UserId $a, UserId $b): array
    {
        return strcmp($a->value, $b->value) < 0 ? [$a->value, $b->value] : [$b->value, $a->value];
    }

    private static function friendship(Row $row): Friendship
    {
        $requester = $row->string('requested_by');
        $userA = $row->string('user_a');

        return new Friendship(
            UserId::fromString($requester),
            UserId::fromString($requester === $userA ? $row->string('user_b') : $userA),
            $row->time('requested_at'),
            $row->nullableTime('accepted_at'),
        );
    }
}
