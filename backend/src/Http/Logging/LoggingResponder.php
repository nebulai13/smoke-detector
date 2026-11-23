<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Logging;

use SmokeDetector\Application\Day\DayView;
use SmokeDetector\Application\Day\ProgressView;
use SmokeDetector\Domain\Day\DayEvent;

/**
 * Writes days, progress and celebration events as the schemas Day, Progress and DayEvent of
 * api/openapi.yaml. Used by the answers of sync, check-in, days and dashboard.
 */
final readonly class LoggingResponder
{
    /**
     * @return array<string, mixed>
     */
    public function day(DayView $day): array
    {
        return [
            'date' => $day->date->value,
            'count' => $day->count,
            'limit' => $day->limit,
            'remaining' => $day->remaining(),
            'checkedIn' => $day->checkedIn,
            'status' => $day->final ? 'final' : 'provisional',
        ];
    }

    /**
     * @param list<DayView> $days
     *
     * @return list<array<string, mixed>>
     */
    public function days(array $days): array
    {
        return array_map($this->day(...), $days);
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(ProgressView $progress): array
    {
        return [
            'checkInStreak' => ['current' => $progress->streaks->checkInCurrent, 'longest' => $progress->streaks->checkInLongest],
            'underLimitStreak' => ['current' => $progress->streaks->underLimitCurrent, 'longest' => $progress->streaks->underLimitLongest],
            'freezes' => $progress->streaks->freezesHeld,
            'xp' => $progress->totalXp,
            'level' => [
                'number' => $progress->level->level,
                'xpIntoLevel' => $progress->level->xpIntoLevel,
                'xpForNextLevel' => $progress->level->xpForNextLevel,
            ],
            'provisional' => $progress->provisional,
        ];
    }

    /**
     * @param list<DayEvent> $events
     *
     * @return list<array<string, mixed>> In the order given. "value" is absent for a type that has none.
     */
    public function events(array $events): array
    {
        return array_map(
            static fn (DayEvent $event): array => $event->value === null
                ? ['type' => $event->type->value]
                : ['type' => $event->type->value, 'value' => $event->value],
            $events,
        );
    }
}
