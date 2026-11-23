<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Settings;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\TransactionRunner;

/**
 * PUT /me/settings (SRS FR-GAME-09, HLD §4.11): the user's choice, which counts at once.
 */
final readonly class SaveSettings
{
    public function __construct(
        private TransactionRunner $transactions,
        private UserRepository $users,
        private UserSettings $settings,
    ) {
    }

    /**
     * @return Settings The settings as they are now.
     *
     * @throws AccountNotFound When the account was deleted in the meantime.
     */
    public function execute(UserId $userId, Settings $settings): Settings
    {
        return $this->transactions->run(function () use ($userId, $settings): Settings {
            // The lock comes first (HLD §4.5 "Transactions and locks"), so no row outlives a deletion.
            $this->users->lock($userId) ?? throw new AccountNotFound();
            $this->settings->save($userId, $settings);

            return $settings;
        });
    }
}
