<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;

/**
 * Every XP award of every user (HLD §4.3 "xp_events"). Append-only: written at finalization and
 * never changed.
 */
interface XpLedger
{
    public function append(XpEvent $event): void;

    /**
     * @return list<XpEvent> By date, then by id.
     */
    public function ofUser(UserId $userId): array;

    /**
     * The XP of the user written to the ledger from $from on and before $until (HLD §4.9 "Weekly XP
     * ranking"): by the time an entry was written, not by the day it belongs to.
     */
    public function earnedBetween(UserId $userId, DateTimeImmutable $from, DateTimeImmutable $until): int;
}
