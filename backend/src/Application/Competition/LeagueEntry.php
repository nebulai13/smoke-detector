<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use SmokeDetector\Application\Friends\PublicProfile;

/**
 * One member of a league as the viewer sees them (FR-FRND-03, HLD §4.9 "What a user sees"). No
 * baseline, no count, no totals; a friend's percentage only once the viewer's league is final and
 * the friend's result is stored, so a responder cannot let out more.
 */
final readonly class LeagueEntry
{
    public function __construct(
        public PublicProfile $user,
        /** The viewer's own entry. */
        public bool $you,
        /** Null for somebody without a day that counts, and then every figure is null too. */
        public ?int $rank,
        /** Whole percent, rounded half away from zero. Null where it may not be shown. */
        public ?int $reductionPercent,
        public ?int $smokeFreeDays,
        public ?int $checkIns,
        public ?int $countedDays,
    ) {
    }
}
