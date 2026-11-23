<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use SmokeDetector\Application\Friends\PublicProfile;

/**
 * One member of the weekly XP ranking (FR-COMP-07).
 */
final readonly class XpLeagueEntry
{
    public function __construct(
        public PublicProfile $user,
        public bool $you,
        /** 1 is the most XP. The same XP shares a rank. */
        public int $rank,
        /** The XP written to the ledger in the viewer's week. */
        public int $xp,
    ) {
    }
}
