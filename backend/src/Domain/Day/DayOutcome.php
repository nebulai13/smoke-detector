<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Day;

use InvalidArgumentException;

/**
 * The facts about one finalized day: how many sessions were logged, the limit that applied,
 * and whether the user checked the day in. Every scoring rule builds on this.
 */
final readonly class DayOutcome
{
    private function __construct(
        public bool $checkedIn,
        public int $count,
        public int $limit,
    ) {
        if ($count < 0) {
            throw new InvalidArgumentException('A daily count cannot be negative.');
        }
        if ($limit < 0) {
            throw new InvalidArgumentException('A daily limit cannot be negative.');
        }
    }

    public static function checkedIn(int $count, int $limit): self
    {
        return new self(true, $count, $limit);
    }

    /**
     * A day the user never confirmed. Logs may exist, but the count is not trusted as complete:
     * it earns nothing, it ends the under-limit streak even when a streak freeze keeps the check-in
     * streak, and in competitions it only counts where it is above the frozen baseline.
     */
    public static function missed(int $count, int $limit): self
    {
        return new self(false, $count, $limit);
    }

    /**
     * Only a checked-in day can be under the limit, so skipping the check-in is never rewarded.
     */
    public function isUnderLimit(): bool
    {
        return $this->checkedIn && $this->count <= $this->limit;
    }

    public function isSmokeFree(): bool
    {
        return $this->checkedIn && $this->count === 0;
    }
}
