<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Recovery\MilestoneRecords;
use SmokeDetector\Application\Recovery\ReachedMilestone;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The health milestones in the `user_milestones` table (HLD §4.3, §4.11): one row per user and
 * milestone, never changed.
 */
final readonly class PdoMilestoneRecords implements MilestoneRecords
{
    public function __construct(private Sql $sql)
    {
    }

    public function add(ReachedMilestone $milestone): bool
    {
        // INSERT IGNORE would also hide other errors. A key that is there already updates nothing,
        // so the first time stays, and the count of changed rows tells whether it was stored now.
        return $this->sql->execute(
            'INSERT INTO user_milestones (user_id, milestone_key, reached_at) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE reached_at = reached_at',
            [$milestone->userId->value, $milestone->milestone->value, Row::sqlTime($milestone->reachedAt)],
        ) === 1;
    }

    public function ofUser(UserId $userId): array
    {
        $byKey = [];
        foreach ($this->sql->rows('SELECT milestone_key, reached_at FROM user_milestones WHERE user_id = ?', [$userId->value]) as $columns) {
            $row = new Row($columns);
            $byKey[$row->string('milestone_key')] = $row->time('reached_at');
        }

        $reached = [];
        // In the order of the cases, which no column holds. A key the code does not know is left out.
        foreach (HealthMilestone::cases() as $milestone) {
            if (isset($byKey[$milestone->value])) {
                $reached[] = new ReachedMilestone($userId, $milestone, $byKey[$milestone->value]);
            }
        }

        return $reached;
    }

    public function reachedBy(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $byUser = [];
        $rows = $this->sql->rows(
            'SELECT user_id, milestone_key FROM user_milestones WHERE user_id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ') '
            . 'ORDER BY user_id',
            array_map(static fn (UserId $userId): string => $userId->value, $userIds),
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $byUser[$row->string('user_id')][$row->string('milestone_key')] = true;
        }

        // By user id, as the rows came. A user who has reached nothing has no row and is left out.
        return array_map(
            static fn (array $keys): array => array_values(array_filter(
                HealthMilestone::cases(),
                static fn (HealthMilestone $milestone): bool => isset($keys[$milestone->value]),
            )),
            $byUser,
        );
    }
}
