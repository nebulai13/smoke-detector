<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Privacy;

use SmokeDetector\Application\Account\UserId;

/**
 * Removes what one table holds about a user, for account deletion (FR-ACC-08).
 */
interface UserDataEraser
{
    /**
     * Called inside the transaction of the deletion, while the account still exists. Afterwards no
     * row of the table may refer to the user or hold anything about the user. Erasing for a user
     * who has nothing in the table does nothing.
     */
    public function erase(UserId $userId): void;
}
