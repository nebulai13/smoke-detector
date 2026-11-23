<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;

/**
 * One local day of a user as stored (HLD §4.3 "day_summaries"): its sessions, its check-in, and
 * once it is final, the result of finalization.
 */
final readonly class DayRecord
{
    public function __construct(
        public UserId $userId,
        public LocalDate $date,
        /** The sum of the counts of the day's logs that are not deleted. */
        public int $count,
        public ?DateTimeImmutable $checkedInAt = null,
        /** Null until the day is final. */
        public ?DayResult $result = null,
    ) {
    }

    public function isCheckedIn(): bool
    {
        return $this->checkedInAt !== null;
    }

    /**
     * Whether finalization has decided the day. A final day never changes again (FR-LOG-07).
     */
    public function isFinal(): bool
    {
        return $this->result !== null;
    }
}
