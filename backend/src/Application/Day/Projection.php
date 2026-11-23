<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\DayFinalizer;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Level\LevelCalculator;

/**
 * What the days that are not final yet give, as they stand (HLD §4.6 "Provisional feedback",
 * FR-GAME-06): the stored progress run through the domain's DayFinalizer for the open days, in
 * calendar order. Calculated on every request and never stored, so it cannot drift from the days.
 *
 * - A checked-in day counts as checked in, with its count and its limit.
 * - A day that is over without a check-in counts as missed.
 * - A day that is not over and not checked in (today) is left out: it is not missed yet.
 */
final readonly class Projection
{
    /**
     * @param array<string, list<DayEvent>> $events By date ("YYYY-MM-DD"), for the projected days.
     */
    private function __construct(
        private ProgressRecord $stored,
        private UserProgress $progress,
        private array $events,
        private LevelCalculator $levels,
    ) {
    }

    /**
     * @param list<DayRecord> $records The stored days of the user from the next day to finalize to
     *                                 today. A day without a record has no log and no check-in.
     */
    public static function of(Calendar $calendar, ProgressRecord $stored, array $records, LocalDate $today): self
    {
        $byDate = [];
        foreach ($records as $record) {
            $byDate[$record->date->value] = $record;
        }

        $finalizer = new DayFinalizer($calendar->rules);
        $progress = $stored->progress;
        $events = [];
        // Every day before today is over: without a check-in it is missed, because a later day has
        // begun. Today is not missed yet: it is in the projection once it is checked in.
        $todayCheckedIn = ($byDate[$today->value] ?? null)?->isCheckedIn() ?? false;
        $last = $todayCheckedIn ? $today : $today->plusDays(-1);
        for ($date = $stored->nextDay; !$date->isAfter($last); $date = $date->plusDays(1)) {
            $record = $byDate[$date->value] ?? null;
            $finalization = $finalizer->finalize($progress, $calendar->outcomeOn($date, $record->count ?? 0, $record?->isCheckedIn() ?? false));
            $progress = $finalization->progress();
            $events[$date->value] = $finalization->events;
        }

        return new self($stored, $progress, $events, new LevelCalculator($calendar->rules->level));
    }

    /**
     * @return list<DayEvent> What the day gives to celebrate in the projection. Empty for a day
     *                        that is not in it.
     */
    public function eventsOn(LocalDate $date): array
    {
        return $this->events[$date->value] ?? [];
    }

    public function view(): ProgressView
    {
        return new ProgressView(
            $this->progress->streaks,
            $this->progress->totalXp,
            $this->levels->progressFor($this->progress->totalXp),
            // Compared by value: the open days are provisional only when they change something.
            $this->progress != $this->stored->progress,
        );
    }
}
