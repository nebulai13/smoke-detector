<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use LogicException;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\ValidationFailed;

/**
 * GET /days?from&to (HLD §4.6, FR-LOG-05): the days of a range with count, limit, check-in and
 * whether they are final. Only the days the user has: from the first day of the plan to today.
 */
final readonly class ShowDays
{
    /** The longest range one request may ask for: about a quarter of a year. */
    public const int MAX_DAYS = 92;

    public function __construct(
        private ClockInterface $clock,
        private Calendars $calendars,
        private DayRecords $days,
        private ProgressRecords $progress,
    ) {
    }

    /**
     * @return list<DayView> Oldest first, one per day, also for a day without any log.
     *
     * @throws ValidationFailed When $to is before $from or the range is longer than MAX_DAYS.
     * @throws PlanRequired     Before the onboarding.
     */
    public function execute(UserId $userId, LocalDate $from, LocalDate $to): array
    {
        if ($to->isBefore($from)) {
            throw ValidationFailed::forField('to', ErrorCode::OutOfRange, 'Must not be before from.');
        }
        if ($from->daysUntil($to) >= self::MAX_DAYS) {
            throw ValidationFailed::forField('to', ErrorCode::OutOfRange, 'The range may span at most ' . self::MAX_DAYS . ' days.');
        }

        $calendar = $this->calendars->of($userId) ?? throw new PlanRequired();
        $stored = $this->progress->find($userId) ?? throw new LogicException('A plan exists without progress.');
        $first = $from->isBefore($calendar->plan->startDate) ? $calendar->plan->startDate : $from;
        $today = $calendar->today($this->clock->now());
        $last = $to->isAfter($today) ? $today : $to;

        $byDate = [];
        foreach ($this->days->between($userId, $first, $last) as $record) {
            $byDate[$record->date->value] = $record;
        }

        $views = [];
        for ($date = $first; !$date->isAfter($last); $date = $date->plusDays(1)) {
            $views[] = $calendar->dayView($date, $byDate[$date->value] ?? null, $stored->nextDay);
        }

        return $views;
    }
}
