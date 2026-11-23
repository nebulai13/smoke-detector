<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;

/**
 * The invite codes of the users (HLD §4.3 "invite_codes"): one per user at most, and no code
 * belongs to two users.
 */
interface InviteCodes
{
    /**
     * The code of the user, or null before the first one was made.
     */
    public function ofUser(UserId $userId): ?InviteCode;

    /**
     * Whose code this is, or null when it is nobody's (any more).
     */
    public function owner(InviteCode $code): ?UserId;

    /**
     * Gives the user this code, made at $at. A code the user had before leads nowhere from now on.
     * Giving the user their own code again is no conflict (the time is the new one).
     *
     * @return bool False, and nothing changed, when the code belongs to another user.
     */
    public function assign(UserId $userId, InviteCode $code, DateTimeImmutable $at): bool;
}
