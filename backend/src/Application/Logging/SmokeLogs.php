<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Logging;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;

/**
 * The logs of all users (HLD §4.3 "smoke_logs").
 */
interface SmokeLogs
{
    /**
     * The log with that id, whoever owns it, deleted or not.
     */
    public function find(string $id): ?SmokeLog;

    /**
     * Stores a new log, or replaces the stored log of that id: everything except its owner and
     * when it was first stored.
     *
     * @return bool False, with nothing stored, when the id belongs to a log of another user (also
     *              when that user stores it at the same moment). A log never changes its owner.
     */
    public function save(SmokeLog $log): bool;

    /**
     * The sessions of a user's day: the sum of the counts of its logs that are not deleted.
     */
    public function sessionsOn(UserId $userId, LocalDate $date): int;
}
