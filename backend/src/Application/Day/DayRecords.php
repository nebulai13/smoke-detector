<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;

/**
 * The days of all users (HLD §4.3 "day_summaries").
 */
interface DayRecords
{
    public function find(UserId $userId, LocalDate $date): ?DayRecord;

    /**
     * Stores the day, replacing everything stored for that user and date.
     */
    public function save(DayRecord $day): void;

    /**
     * @return list<DayRecord> The stored days of the user from $from to $to, both included, oldest
     *                         first. Empty when $to is before $from.
     */
    public function between(UserId $userId, LocalDate $from, LocalDate $to): array;

    /**
     * The latest stored day of the user up to $day, included, that has no check-in; null without one.
     */
    public function lastMissedUntil(UserId $userId, LocalDate $day): ?LocalDate;
}
