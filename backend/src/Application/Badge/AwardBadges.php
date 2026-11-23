<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Badge;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\Duels;
use SmokeDetector\Application\Competition\DuelWinner;
use SmokeDetector\Application\Day\Calendar;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Badge\BadgeEvaluator;
use SmokeDetector\Domain\Day\DayOutcome;
use SmokeDetector\Domain\Streak\StreakState;
use SmokeDetector\Domain\Xp\LeaguePeriod;

/**
 * Stores the badges that something which has just become final justifies (HLD §4.10 "When a badge is
 * awarded", SRS FR-GAME-08): a day, a decided duel, a league rank. BadgeEvaluator says which ones.
 *
 * It runs inside the transaction of the step that made the thing final, so a step that is rolled back
 * leaves no badge behind. A badge the user holds already is not stored again: the first award stays.
 */
final readonly class AwardBadges
{
    public function __construct(
        private BadgeRecords $badges,
        private Duels $duels,
    ) {
    }

    /**
     * After a day of the user of the calendar has become final.
     *
     * @param StreakState $streaks The streaks after the day.
     */
    public function dayFinalized(Calendar $calendar, LocalDate $date, DayOutcome $outcome, StreakState $streaks, DateTimeImmutable $now): void
    {
        $this->award($calendar->plan->userId, BadgeEvaluator::forDay(
            $outcome,
            $streaks,
            $calendar->limitOn($calendar->plan->startDate),
            // The target of that day: a goal chosen since may count from a later day only.
            $calendar->targetOn($date),
        ), $now);
    }

    /**
     * After a duel was stored. Only a decided duel with a winner earns something, and only the winner;
     * the duel must be stored already, because the duels won are counted from what is stored.
     */
    public function duelStored(Duel $duel): void
    {
        $decision = $duel->decision;
        if ($decision === null) {
            return;
        }
        $winnerId = match ($decision->winner) {
            DuelWinner::Challenger => $duel->challengerId,
            DuelWinner::Opponent => $duel->opponentId,
            DuelWinner::Draw => null,
        };
        if ($winnerId === null) {
            return;
        }
        $this->award($winnerId, BadgeEvaluator::forDuelsWon($this->duels->wonBy($winnerId)), $decision->decidedAt);
    }

    /**
     * After a league result of the user was ranked.
     *
     * @param int $participants The user and the friends who had a result.
     */
    public function leagueRanked(UserId $userId, LeaguePeriod $type, int $rank, int $participants, Calendar $calendar, DateTimeImmutable $now): void
    {
        $this->award($userId, BadgeEvaluator::forLeague($type, $rank, $participants, $calendar->rules->xp->leagueMinParticipants), $now);
    }

    /**
     * Stores those of the justified badges the user does not hold yet. The badges held are read once,
     * so a day that justifies what is held already writes nothing.
     *
     * @param list<Badge> $justified
     */
    private function award(UserId $userId, array $justified, DateTimeImmutable $now): void
    {
        if ($justified === []) {
            return;
        }
        $held = $this->badges->heldBy([$userId])[$userId->value] ?? [];
        foreach ($justified as $badge) {
            if (!in_array($badge, $held, true)) {
                $this->badges->add(new AwardedBadge($userId, $badge, $now));
            }
        }
    }
}
