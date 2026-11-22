<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use DateInterval;
use DateTimeImmutable;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Time\LocalDays;

/**
 * When the cron job has work for a user (HLD §4.6 "Day finalization", "Plan and limit"): the
 * instants user_progress holds, worked out from the user's days.
 */
final readonly class Schedule
{
    /** A day is final this long after it ended: until then its logs can be corrected. */
    public const int FINAL_AFTER_HOURS = 48;

    /** A calendar week: the weekly baselines are due every Monday. */
    public const int DAYS_PER_WEEK = 7;

    /** The first weekly baseline waits until the plan is this many days old. */
    public const int FIRST_BASELINE_AFTER_DAYS = 7;

    /**
     * When the day becomes final.
     */
    public static function finalizeDueAt(LocalDays $days, LocalDate $day): DateTimeImmutable
    {
        return $days->endOf($day)->add(new DateInterval('PT' . self::FINAL_AFTER_HOURS . 'H'));
    }

    /**
     * The Monday of the first weekly baseline: the first Monday on which a plan that began on
     * $startDate is FIRST_BASELINE_AFTER_DAYS days old.
     */
    public static function firstBaselineDay(LocalDate $startDate): LocalDate
    {
        $old = $startDate->plusDays(self::FIRST_BASELINE_AFTER_DAYS);

        // ISO weekdays run from 1 (Monday) to 7 (Sunday).
        return $old->plusDays((8 - $old->isoWeekday()) % self::DAYS_PER_WEEK);
    }
}
