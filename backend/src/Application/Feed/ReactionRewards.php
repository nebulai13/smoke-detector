<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Badge\AwardBadges;
use SmokeDetector\Application\Competition\CompetitionRewards;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\XpLedger;
use SmokeDetector\Domain\Badge\BadgeEvaluator;
use SmokeDetector\Domain\Xp\XpCalculator;
use SmokeDetector\Domain\Xp\XpSource;

/**
 * What a new reaction earns (HLD §4.13, SRS FR-GAME-01, FR-GAME-08): the XP of a reaction, up to the
 * daily maximum of the user's own day, and the badge for the reactions sent. Inside the caller's
 * transaction.
 *
 * The XP is no smoking data: it is booked and added to the progress at once, not when a day becomes
 * final. Before the onboarding there is no day and no progress, so nothing is earned.
 */
final readonly class ReactionRewards
{
    public function __construct(
        private Calendars $calendars,
        private XpLedger $ledger,
        private CompetitionRewards $rewards,
        private AwardBadges $badges,
        private Reactions $reactions,
    ) {
    }

    /**
     * After a reaction was stored that the user did not have before.
     *
     * @return int The XP paid.
     */
    public function newReaction(UserId $userId, DateTimeImmutable $now): int
    {
        $xp = 0;
        $calendar = $this->calendars->of($userId);
        if ($calendar !== null) {
            $earned = $this->ledger->earnedOn($userId, $calendar->today($now), XpSource::Reaction);
            $xp = $this->rewards->pay($userId, $calendar, (new XpCalculator($calendar->rules->xp))->forReaction($earned), $now);
        }
        $this->badges->reactionSent($userId, $this->reactions->countSentBy($userId), $now);

        return $xp;
    }
}
