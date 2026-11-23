<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Settings;

use SmokeDetector\Application\Account\UserId;

/**
 * The settings of all users (HLD §4.3 "user_settings"): one row per user, made by the first choice.
 * No row means the defaults.
 */
interface UserSettings
{
    /**
     * @return Settings The stored settings, or the defaults when the user never chose.
     */
    public function find(UserId $userId): Settings;

    /**
     * @param list<UserId> $userIds
     *
     * @return array<string, Settings> By user id, with every user asked for: the defaults for who never chose.
     */
    public function findAll(array $userIds): array;

    /**
     * Stores the settings of the user, replacing what was stored.
     */
    public function save(UserId $userId, Settings $settings): void;
}
