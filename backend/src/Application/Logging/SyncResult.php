<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Logging;

use SmokeDetector\Application\Day\DayView;

/**
 * The answer to a sync: one result per item, in the order of the request, and the days of the
 * accepted items as they are now, oldest first.
 */
final readonly class SyncResult
{
    /**
     * @param list<ItemResult> $items
     * @param list<DayView>    $days
     */
    public function __construct(
        public array $items,
        public array $days,
    ) {
    }
}
