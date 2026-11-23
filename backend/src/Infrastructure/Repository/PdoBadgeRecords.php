<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Badge\AwardedBadge;
use SmokeDetector\Application\Badge\BadgeRecords;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The badges in the `user_badges` table (HLD §4.3): one row per user and badge, never changed.
 */
final readonly class PdoBadgeRecords implements BadgeRecords
{
    public function __construct(private Sql $sql)
    {
    }

    public function add(AwardedBadge $badge): bool
    {
        // INSERT IGNORE would also hide other errors. A key that is there already updates nothing,
        // so the first award stays, and the count of changed rows tells whether it was stored now.
        return $this->sql->execute(
            'INSERT INTO user_badges (user_id, badge_key, awarded_at) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE awarded_at = awarded_at',
            [$badge->userId->value, $badge->badge->value, Row::sqlTime($badge->awardedAt)],
        ) === 1;
    }

    public function ofUser(UserId $userId): array
    {
        $byKey = [];
        foreach ($this->sql->rows('SELECT badge_key, awarded_at FROM user_badges WHERE user_id = ?', [$userId->value]) as $columns) {
            $row = new Row($columns);
            $byKey[$row->string('badge_key')] = $row->time('awarded_at');
        }

        $badges = [];
        // In the order of the cases, which no column holds. A key the code does not know is left out.
        foreach (Badge::cases() as $badge) {
            if (isset($byKey[$badge->value])) {
                $badges[] = new AwardedBadge($userId, $badge, $byKey[$badge->value]);
            }
        }

        return $badges;
    }

    public function heldBy(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $byUser = [];
        $rows = $this->sql->rows(
            'SELECT user_id, badge_key FROM user_badges WHERE user_id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ') '
            . 'ORDER BY user_id',
            array_map(static fn (UserId $userId): string => $userId->value, $userIds),
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $byUser[$row->string('user_id')][$row->string('badge_key')] = true;
        }

        // By user id, as the rows came. A user without a badge has no row and is left out.
        return array_map(
            static fn (array $keys): array => array_values(array_filter(Badge::cases(), static fn (Badge $badge): bool => isset($keys[$badge->value]))),
            $byUser,
        );
    }
}
