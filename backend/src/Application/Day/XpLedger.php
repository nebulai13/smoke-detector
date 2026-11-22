<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

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
}
