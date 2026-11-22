<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use SmokeDetector\Application\Plan\CurrentPlans;

/**
 * GET /me (HLD §4.5 "Account"): the profile of the user, and the plan with today's limit.
 */
final readonly class ShowAccount
{
    public function __construct(
        private UserRepository $users,
        private CurrentPlans $plans,
    ) {
    }

    /**
     * @throws AccountNotFound When the account was deleted in the meantime.
     */
    public function execute(UserId $userId): Account
    {
        $user = $this->users->find($userId) ?? throw new AccountNotFound();

        return new Account($user, $this->plans->of($user->id));
    }
}
