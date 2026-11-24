<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Nudge;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;

/**
 * A reminder of one user to a friend to check in, without text (HLD §4.3 "nudges", §4.14). One per
 * sender, receiver and day of the sender.
 */
final readonly class Nudge
{
    public function __construct(
        public UserId $from,
        public UserId $to,
        /** The local day of the sender, in the zone of the sender's account. */
        public LocalDate $day,
        public DateTimeImmutable $createdAt,
        /** Whether the receiver had not checked in that day, so that it is shown. Never told to the sender. */
        public bool $delivered,
    ) {
    }
}
