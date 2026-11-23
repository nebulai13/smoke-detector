<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use SmokeDetector\Application\Time\LocalDate;

/**
 * A day as the user sees it (schema Day): its sessions against its limit, whether it is checked
 * in, and whether it is final.
 */
final readonly class DayView
{
    public function __construct(
        public LocalDate $date,
        public int $count,
        /** For a final day the limit it was finalized with; for an open day the one calculated now. */
        public int $limit,
        public bool $checkedIn,
        public bool $final,
    ) {
    }

    /**
     * What is left of the limit. Never below 0: going over is shown as nothing left, not as a debt
     * (FR-LOG-06).
     */
    public function remaining(): int
    {
        return max(0, $this->limit - $this->count);
    }
}
