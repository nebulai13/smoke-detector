<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Xp\DuelResult;

/**
 * A duel as one of the two sees it (HLD §4.8 "What the opponent sees"). Percentages only, never
 * a baseline, a count or a limit; of the opponent of a running duel only who is ahead.
 */
final readonly class DuelView
{
    public function __construct(
        public string $id,
        /** As it is by the clock: a proposal whose time is over is expired. */
        public DuelStatus $status,
        public int $periodDays,
        /** Whether the user proposed the duel. */
        public bool $challenger,
        /** Null when the two are not friends any more, or the account is gone. */
        public ?PublicProfile $opponent,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        /** The first day in the user's own calendar. Null before the duel was accepted. */
        public ?LocalDate $startsOn,
        public ?LocalDate $endsOn,
        /** Null while no day counts, and for a duel without a result. */
        public ?SideStanding $mine,
        /**
         * As $mine, and null whenever $opponent is. While the duel runs, without its percentage:
         * a figure that moves every day could be calculated back.
         */
        public ?SideStanding $theirs,
        /**
         * The user against the opponent while the duel runs. Null unless both sides have a day
         * that counts and the opponent is visible, and for every duel that does not run.
         */
        public ?DuelStanding $standing,
        /** Of a completed duel. */
        public ?DuelResult $outcome,
        /** What the user earned with a completed duel. */
        public ?int $xp,
        /** True while the duel runs: the sides can still change. */
        public bool $provisional,
    ) {
    }
}
