<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use LogicException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Competition\CompetitionScoreCalculator;
use SmokeDetector\Domain\Competition\FrozenBaseline;
use SmokeDetector\Domain\Plan\Baseline;

/**
 * Reads the days of one side of a duel (HLD §4.8 "Own calendar", "Score"): the N dates of the
 * user's own calendar from the side's first day, each against the frozen baseline.
 *
 * A day with a check-in counts with its count. A day without one counts as missed: as the
 * baseline, or as what was logged when that is more. A day without a row is a day without a
 * check-in and without a log.
 */
final readonly class DuelSides
{
    public function __construct(
        private Calendars $calendars,
        private DayRecords $records,
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

    private function side(UserId $userId, LocalDate $start, int $periodDays, ?int $b0Tenths, ?DateTimeImmutable $now): SideDays
    {
        // Proposing and accepting need a plan, and a plan is deleted with its account only.
        $calendar = $this->calendars->of($userId) ?? throw new LogicException('A side of a duel has no plan.');
        $baseline = FrozenBaseline::freeze(
            $b0Tenths === null ? $calendar->baselineOn($start)->value : Baseline::fromTenths($b0Tenths),
            $calendar->rules->competition,
        );
        $today = $now === null ? null : $calendar->today($now);
        $last = $start->plusDays($periodDays - 1);

        $byDate = [];
        foreach ($this->records->between($userId, $start, $last) as $record) {
            $byDate[$record->date->value] = $record;
        }

        $calculator = new CompetitionScoreCalculator();
        $counted = [];
        $percents = [];
        for ($day = 1, $date = $start; !$date->isAfter($last); $day++, $date = $date->plusDays(1)) {
            $record = $byDate[$date->value] ?? null;
            $checkedIn = $record?->isCheckedIn() ?? false;
            // A later day cannot be checked in yet, so "today once it is checked in" is all of it.
            if ($today === null || $date->isBefore($today) || $checkedIn) {
                $outcome = $calendar->outcomeOn($date, $record->count ?? 0, $checkedIn);
                $counted[] = $outcome;
                $percents[$day] = SideStanding::percent($calculator->score($baseline, [$outcome])->reduction);
            }
        }

        return new SideDays($calendar, $baseline, $counted, $percents);
    }
}
