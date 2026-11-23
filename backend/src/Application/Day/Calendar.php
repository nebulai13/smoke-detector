<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use DateTimeImmutable;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\PlanTerms;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Time\LocalDays;
use SmokeDetector\Domain\Day\DayOutcome;
use SmokeDetector\Domain\Plan\ReductionPlan;
use SmokeDetector\Domain\Rules\RuleSet;

/**
 * The days of one user who has a plan (HLD §4.6 "Local days", "Plan and limit"): which day it is,
 * and what the limit of any day is.
 *
 * The limit of day D is calculated from the baseline valid on D and the plan terms valid on D, so a
 * change counts from the day it is valid from and every past day can be calculated again (NFR-04).
 * Pure calculation: Calendars reads what it needs.
 */
final readonly class Calendar
{
    /**
     * @param non-empty-list<BaselineEntry> $baselines Oldest first.
     * @param list<PlanTerms>               $terms     Oldest first. The onboarding writes the first.
     */
    public function __construct(
        /** The plan as the user chose it last. Its goal and taper speed may count from tomorrow only. */
        public Plan $plan,
        public LocalDays $days,
        private array $baselines,
        private array $terms,
        /** The rules new results are calculated with. */
        public RuleSet $rules,
    ) {
    }

    /**
     * The user's day at that instant. Never from the zone of the account, which may already be
     * the zone of tomorrow.
     */
    public function today(DateTimeImmutable $now): LocalDate
    {
        return $this->days->dateOf($now);
    }

    /**
     * The baseline entry valid on the date: the newest one from that date or before. A date before
     * the first entry has the first.
     */
    public function baselineOn(LocalDate $date): BaselineEntry
    {
        $valid = $this->baselines[0];
        foreach ($this->baselines as $entry) {
            if (!$entry->validFrom->isAfter($date)) {
                $valid = $entry;
            }
        }

        return $valid;
    }

    /**
     * The plan terms valid on the date, chosen in the same way as the baseline.
     */
    private function termsOn(LocalDate $date): PlanTerms
    {
        $valid = $this->terms[0];
        foreach ($this->terms as $terms) {
            if (!$terms->validFrom->isAfter($date)) {
                $valid = $terms;
            }
        }

        return $valid;
    }

    /**
     * The daily limit the plan valid on the date ends at (0 to quit). Not the goal of $plan, which
     * may count from tomorrow only.
     */
    public function targetOn(LocalDate $date): int
    {
        return $this->termsOn($date)->goal->target;
    }

    /**
     * Sessions allowed on the date (FR-PLAN-01).
     */
    public function limitOn(LocalDate $date): int
    {
        $terms = $this->termsOn($date);

        return ReductionPlan::dailyLimit($this->baselineOn($date)->value, $terms->taperSpeed, $terms->goal, $this->rules->plan);
    }

    /**
     * The facts the scoring rules get for the date (HLD §4.6): its count measured against its limit,
     * checked in or missed. The projection and finalization build it here alike, so a day gives the
     * same result before and after it is final.
     */
    public function outcomeOn(LocalDate $date, int $count, bool $checkedIn): DayOutcome
    {
        $limit = $this->limitOn($date);

        return $checkedIn ? DayOutcome::checkedIn($count, $limit) : DayOutcome::missed($count, $limit);
    }

    /**
     * From when the newest choice of goal and taper speed counts, while it does not count yet.
     */
    public function pendingFrom(LocalDate $today): ?LocalDate
    {
        $newest = $this->terms[count($this->terms) - 1];

        return $newest->validFrom->isAfter($today) ? $newest->validFrom : null;
    }

    /**
     * Whether the date takes nothing more, no log and no check-in (HLD §4.6 "POST /logs/sync"): it
     * is final, or its 48 hours are over by the clock, whether the cron job has made it final yet or
     * not. So the grace period does not get longer when the cron job is late.
     */
    public function isClosed(LocalDate $date, LocalDate $nextDay, DateTimeImmutable $now): bool
    {
        return $date->isBefore($nextDay) || Schedule::finalizeDueAt($this->days, $date) <= $now;
    }

    /**
     * How the user sees the date. A day before $nextDay is final and keeps the limit it was
     * finalized with; an open day has the limit calculated now. No record is a day without a log.
     */
    public function dayView(LocalDate $date, ?DayRecord $record, LocalDate $nextDay): DayView
    {
        return new DayView(
            $date,
            $record->count ?? 0,
            $record->result->dailyLimit ?? $this->limitOn($date),
            $record?->isCheckedIn() ?? false,
            $date->isBefore($nextDay),
        );
    }
}
