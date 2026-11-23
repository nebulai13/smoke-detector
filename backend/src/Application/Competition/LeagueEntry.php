<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use SmokeDetector\Application\Friends\PublicProfile;

/**
 * One member of a league as the viewer sees them (FR-FRND-03, HLD §4.9 "What a user sees"). No
 * baseline, no count, no totals. While the viewer's league is not final, a friend has a standing
 * against the viewer and no rank; a friend's percentage only once the league is final and the
 * friend's result is stored over enough days, so a responder cannot let out more.
 */
final readonly class LeagueEntry
{
    public function __construct(
        public PublicProfile $user,
        /** The viewer's own entry. */
        public bool $you,
        /** Of everybody with a score once final, of the viewer only before. Without a score every figure is null. */
        public ?int $rank,
        /** Of a friend while the league is not final, against the viewer; null otherwise. */
        public ?DuelStanding $standing,
        /** Whole percent, rounded half away from zero. Null where it may not be shown. */
        public ?int $reductionPercent,
        public ?int $smokeFreeDays,
        public ?int $checkIns,
        public ?int $countedDays,
    ) {
    }
}
