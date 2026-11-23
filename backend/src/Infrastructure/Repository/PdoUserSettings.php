<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The settings in the `user_settings` table (HLD §4.3, §4.11): one row per user, made by the first
 * choice. No row means the defaults.
 */
final readonly class PdoUserSettings implements UserSettings
{
    public function __construct(private Sql $sql)
    {
    }

    public function find(UserId $userId): Settings
    {
        return $this->findAll([$userId])[$userId->value];
    }

    public function findAll(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $stored = [];
        $rows = $this->sql->rows(
            'SELECT user_id, milestone_sharing FROM user_settings WHERE user_id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ')',
            array_map(static fn (UserId $userId): string => $userId->value, $userIds),
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            // A value the code does not know is an error, not a reason to show friends more than was chosen.
            $stored[$row->string('user_id')] = new Settings(MilestoneSharing::from($row->string('milestone_sharing')));
        }

        $found = [];
        foreach ($userIds as $userId) {
            // Everybody who was asked for is in the answer, with the defaults if nothing is stored.
            $found[$userId->value] = $stored[$userId->value] ?? Settings::defaults();
        }
        ksort($found);

        return $found;
    }

    public function save(UserId $userId, Settings $settings): void
    {
        $this->sql->execute(
            'INSERT INTO user_settings (user_id, milestone_sharing) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE milestone_sharing = VALUES(milestone_sharing)',
            [$userId->value, $settings->milestoneSharing->value],
        );
    }
}
