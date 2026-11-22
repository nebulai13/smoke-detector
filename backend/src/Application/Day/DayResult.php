<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use DateTimeImmutable;
use SmokeDetector\Domain\Day\DayEvent;

/**
 * What finalization stores with a day (HLD §4.6 "Day finalization"): the limit it was measured
 * against, the XP and celebration events it gave, whether a freeze covered it, and when and under
 * which rules this was decided.
 */
final readonly class DayResult
{
    /**
     * @param list<DayEvent> $events In the order the domain gives them.
     */
    public function __construct(
        public int $dailyLimit,
        public int $xp,
        public array $events,
        public bool $freezeUsed,
        public DateTimeImmutable $finalizedAt,
        public string $rulesVersion,
    ) {
    }
}
