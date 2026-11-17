<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Streak;

/**
 * Result of advancing the streaks by one finalized day: the new state and what happened on the way.
 */
final readonly class StreakAdvance
{
    /**
     * @param list<StreakEvent> $events In a fixed order, with at most one event per type.
     */
    public function __construct(
        public StreakState $state,
        public array $events,
    ) {
    }

    /**
     * The event of the given type, or null when it did not happen on this day.
     */
    public function event(StreakEventType $type): ?StreakEvent
    {
        foreach ($this->events as $event) {
            if ($event->type === $type) {
                return $event;
            }
        }

        return null;
    }
}
