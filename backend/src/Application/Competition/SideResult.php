<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

/**
 * One side of a decided duel, as it was scored (HLD §4.8 "Score", NFR-04): the frozen baseline,
 * the exact totals in tenths of a session, the tie-breakers and the XP it earned.
 */
final readonly class SideResult
{
    public function __construct(
        /** The frozen baseline B0, tenths of a session per day. */
        public int $b0Tenths,
        public int $actualTenths,
        public int $expectedTenths,
        public int $smokeFreeDays,
        public int $checkIns,
        /** What this side earned. 0 when the rule table pays nothing. */
        public int $xp,
    ) {
    }
}
