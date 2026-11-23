<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Day\UserProgress;

/**
 * What a user has built up over the days that are final, and where finalization goes on
 * (HLD §4.3 "user_progress"). One per user, created with the plan.
 */
final readonly class ProgressRecord
{
    public function __construct(
        public UserId $userId,
        public UserProgress $progress,
        public int $level,
        /** The next local date to finalize: every earlier day is final. */
        public LocalDate $nextDay,
        /** When $nextDay becomes final: 48 hours after it ended. */
        public DateTimeImmutable $finalizeDueAt,
        /** When the next weekly baseline is calculated: the start of the user's next Monday. */
        public DateTimeImmutable $baselineDueAt,
    ) {
    }
}
