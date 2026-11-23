<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Logging;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\ProductType;
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

    /**
     * When the user's latest log that is not deleted was smoked (SRS FR-GAME-09), or null without one.
     */
    public function lastSessionAt(UserId $userId): ?DateTimeImmutable;

    /**
     * @return list<ProductType> The products of the user's logs that are not deleted, each once, in
     *                           the order of the ProductType cases.
     */
    public function productsOf(UserId $userId): array;
}
