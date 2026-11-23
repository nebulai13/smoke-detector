<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use SmokeDetector\Application\Day\Calendar;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Xp\LeaguePeriod;

/**
 * Stores the final league results of a user (HLD §4.9 "Final scores"). Called by FinalizeDays in the
 * transaction that makes a day final: when that day is the last day of a period, the user's result
 * for that period is stored, to be ranked by RankLeagues from now on.
 *
 * Scored as a side of a duel (§4.8 "Score"): the days of the period from the first day of the plan
 * if that is later, against the baseline valid on the first counted day, frozen.
 */
final readonly class LeagueScores
{
    public function __construct(
        private LeagueResults $results,
        private DayRecords $records,
    ) {
    }

    /**
     * @param Calendar $calendar The calendar of the user whose day has just become final.
     * @param LocalDate $date    That day. Its record is stored already.
     */
    public function dayFinalized(Calendar $calendar, LocalDate $date, DateTimeImmutable $now): void
    {
        foreach (LeaguePeriod::cases() as $type) {
            $period = CalendarPeriod::containing($type, $date);
            if (!$period->last()->equals($date)) {
                continue;
            }
            $startDate = $calendar->plan->startDate;
            $start = $startDate->isAfter($period->first()) ? $startDate : $period->first();
            $days = DuelSides::read($this->records, $calendar, $start, $date, null, null);
            $score = $days->score();
            // A day that becomes final is never before the first day of the plan, so one day counts at least.
            \assert($score !== null);

            $this->results->save(new LeagueResult(
                $calendar->plan->userId,
                $period,
                $days->baseline->tenths,
                $score->reduction->actualTenths,
                $score->reduction->expectedTenths,
                $score->smokeFreeDays,
                $score->checkIns,
                $score->countedDays,
                $calendar->rules->version,
                $now,
                $now,
            ));
        }
    }
}
