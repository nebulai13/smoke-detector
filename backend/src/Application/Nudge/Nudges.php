<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Nudge;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;

/**
 * The nudges between users (HLD §4.3 "nudges", §4.14), delivered or not.
 */
interface Nudges
{
    public function find(UserId $from, UserId $to, LocalDate $day): ?Nudge;

    /**
     * Stores the nudge, unless the sender has one to that receiver on that day already: then the
     * first stays and nothing is stored.
     *
     * @return bool True when it was stored.
     */
    public function add(Nudge $nudge): bool;

    /**
     * @return list<Nudge> The delivered nudges to the user created at $since or later, oldest first.
     */
    public function deliveredTo(UserId $to, DateTimeImmutable $since): array;

    /**
     * @return list<Nudge> The nudges the user sent, oldest first.
     */
    public function sentBy(UserId $from): array;

    /**
     * @return list<Nudge> The nudges sent to the user, also those that were not delivered, oldest first.
     */
    public function receivedBy(UserId $to): array;
}
