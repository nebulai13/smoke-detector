<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Time;

use SmokeDetector\Application\Account\UserId;

/**
 * The time zones of the users and from which local date each counts (HLD §4.3 "timezone_history").
 * LocalDays is built from it.
 */
interface ZoneHistory
{
    /**
     * Stores the change. A change of the same user for the same date is replaced: only the last
     * choice made before a day begins counts for it.
     */
    public function record(UserId $userId, ZoneChange $change): void;

    /**
     * Deletes the user's changes valid from a date after $date: changes that are waiting and are
     * taken back or replaced.
     */
    public function dropAfter(UserId $userId, LocalDate $date): void;

    /**
     * @return list<ZoneChange> Oldest first. Empty for a user without a history.
     */
    public function ofUser(UserId $userId): array;
}
