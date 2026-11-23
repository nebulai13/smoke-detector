<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use LogicException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\Calendar;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Competition\FrozenBaseline;
use SmokeDetector\Domain\Plan\Baseline;

/**
 * Reads the days of one side of a duel (HLD §4.8 "Own calendar", "Score"): the N dates of the
 * user's own calendar from the side's first day, each against the frozen baseline.
 *
 * A day with a check-in counts with its count. A day without one counts as missed: as the
 * baseline, or as what was logged when that is more. A day without a row is a day without a
 * check-in and without a log.
 *
 * Three ways to bound the days: all of them (whole), the days that count so far for the user's own
 * view (soFar, live), and the final days only (final), the only days ever shown of or compared with
 * another user (HLD §4.8 "Of the other side, final days only").
 */
final readonly class DuelSides
{
    public function __construct(
        private Calendars $calendars,
        private DayRecords $records,
        private ProgressRecords $progress,
    ) {
    }

    /**
     * Every day of the side counts: the duel is over (its days are final).
     *
     * @param int|null $b0Tenths The frozen baseline the side was scored with, or null to freeze the
     *                           baseline entry valid on the first day.
     */
    public function whole(UserId $userId, LocalDate $start, int $periodDays, ?int $b0Tenths = null): SideDays
    {
        return $this->side($userId, $start, $periodDays, $b0Tenths, null);
    }

    /**
     * The days that count so far in a running duel (the rule of the projection, HLD §4.6): the days
     * up to yesterday of the user's own calendar, and today once it is checked in.
     */
    public function soFar(UserId $userId, LocalDate $start, int $periodDays, DateTimeImmutable $now): SideDays
    {
        return $this->side($userId, $start, $periodDays, null, $now);
    }

    /**
     * The days of a running duel that are final for the side's user, and no other: what the other
     * one of the two may see of the side, and what who is ahead is decided on.
     */
    public function final(UserId $userId, LocalDate $start, int $periodDays): SideDays
    {
        return self::readFinal($this->records, $this->progress, $this->calendarOf($userId), $start, self::lastDay($start, $periodDays));
    }

    private function side(UserId $userId, LocalDate $start, int $periodDays, ?int $b0Tenths, ?DateTimeImmutable $now): SideDays
    {
        return self::read($this->records, $this->calendarOf($userId), $start, self::lastDay($start, $periodDays), $b0Tenths, $now);
    }

    private static function lastDay(LocalDate $start, int $periodDays): LocalDate
    {
        return $start->plusDays($periodDays - 1);
    }

    private function calendarOf(UserId $userId): Calendar
    {
        // Proposing and accepting need a plan, and a plan is deleted with its account only.
        return $this->calendars->of($userId) ?? throw new LogicException('A side of a duel has no plan.');
    }

    /**
     * The final days from $start to $last of the user whose calendar it is, against the baseline
     * entry valid on $start. The one place that knows what final means: a day before the user's next
     * day to finalize (ProgressRecord::$nextDay). A user without a progress record has no final day.
     * Days that are not final are not even read.
     */
    public static function readFinal(DayRecords $records, ProgressRecords $progress, Calendar $calendar, LocalDate $start, LocalDate $last): SideDays
    {
        $lastFinal = ($progress->find($calendar->plan->userId)->nextDay ?? $start)->plusDays(-1);

        return self::read($records, $calendar, $start, $lastFinal->isBefore($last) ? $lastFinal : $last, null, null);
    }

    /**
     * The days from $start to $last of the user whose calendar it is, against the frozen baseline.
     * Shared with the leagues (HLD §4.9), which count the days of a calendar period in the same way.
     *
     * @param int|null               $b0Tenths The baseline the days were scored with, or null to freeze
     *                                         the baseline entry valid on $start.
     * @param DateTimeImmutable|null $now      Null when every day counts; otherwise the days that count
     *                                         so far at that moment.
     */
    public static function read(DayRecords $records, Calendar $calendar, LocalDate $start, LocalDate $last, ?int $b0Tenths, ?DateTimeImmutable $now): SideDays
    {
        $baseline = FrozenBaseline::freeze(
            $b0Tenths === null ? $calendar->baselineOn($start)->value : Baseline::fromTenths($b0Tenths),
            $calendar->rules->competition,
        );
        $today = $now === null ? null : $calendar->today($now);

        $byDate = [];
        foreach ($records->between($calendar->plan->userId, $start, $last) as $record) {
            $byDate[$record->date->value] = $record;
        }

        $counted = [];
        for ($date = $start; !$date->isAfter($last); $date = $date->plusDays(1)) {
            $record = $byDate[$date->value] ?? null;
            $checkedIn = $record?->isCheckedIn() ?? false;
            // A later day cannot be checked in yet, so "today once it is checked in" is all of it,
            // and the days that count are always the first ones of the side.
            if ($today === null || $date->isBefore($today) || $checkedIn) {
                $counted[] = $calendar->outcomeOn($date, $record->count ?? 0, $checkedIn);
            }
        }

        return new SideDays($calendar, $baseline, $counted);
    }
}
