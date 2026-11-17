<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Day;

use SmokeDetector\Domain\Streak\StreakState;
use SmokeDetector\Domain\Xp\XpAward;
use SmokeDetector\Domain\Xp\XpCalculator;

/**
 * Everything that finalizing one user-day produces. The caller stores it; nothing here is stored yet.
 */
final readonly class DayFinalization
{
    /**
     * @param list<XpAward>  $xpAwards One entry per XP event of this day. Empty for a day without a check-in.
     * @param list<DayEvent> $events   In the order the clients should play them.
     */
    public function __construct(
        /** The streak counters after this day. */
        public StreakState $streaks,
        public array $xpAwards,
        /** The XP total after this day. */
        public int $totalXp,
        public int $levelBefore,
        public int $levelAfter,
        public array $events,
    ) {
    }

    /**
     * XP earned on this day alone.
     */
    public function xpEarned(): int
    {
        return XpCalculator::total($this->xpAwards);
    }

    /**
     * The progress after this day, which is the input for finalizing the next day.
     */
    public function progress(): UserProgress
    {
        return new UserProgress($this->streaks, $this->totalXp);
    }

    /**
     * @return list<DayEvent>
     */
    public function eventsOfType(DayEventType $type): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (DayEvent $event): bool => $event->type === $type,
        ));
    }
}
