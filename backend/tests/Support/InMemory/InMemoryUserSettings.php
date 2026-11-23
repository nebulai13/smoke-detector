<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Application\Settings\UserSettings;

/**
 * The settings of the users, in memory. Behaves like PdoUserSettings (both pass UserSettingsContract).
 */
final class InMemoryUserSettings implements UserSettings, InMemoryState
{
    /** @var array<string, Settings> By user id. */
    private array $settings = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function find(UserId $userId): Settings
    {
        return $this->settings[$userId->value] ?? Settings::defaults();
    }

    public function findAll(array $userIds): array
    {
        $found = [];
        foreach ($userIds as $userId) {
            // Everybody who was asked for is in the answer, with the defaults if nothing is stored.
            $found[$userId->value] = $this->find($userId);
        }
        ksort($found);

        return $found;
    }

    public function save(UserId $userId, Settings $settings): void
    {
        $this->settings[$userId->value] = $settings;
    }

    public function snapshot(): array
    {
        return $this->settings;
    }

    public function restore(array $snapshot): void
    {
        $this->settings = [];
        foreach ($snapshot as $key => $settings) {
            \assert($settings instanceof Settings);
            $this->settings[(string) $key] = $settings;
        }
    }
}
