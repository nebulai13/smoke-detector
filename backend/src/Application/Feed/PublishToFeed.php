<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Xp\DuelResult;

/**
 * Writes the entries of the activity feed (HLD §4.12 "What is written", SRS FR-SOC-01): a level
 * reached, a badge or a health milestone stored for the first time, a duel won or drawn with a
 * reduction.
 *
 * It runs inside the transaction of the step that made the fact final, so a step that is rolled back
 * leaves no entry behind, and nothing comes from an open day. Every entry gets a random id and the
 * time of the step.
 */
final readonly class PublishToFeed
{
    public function __construct(
        private FeedEvents $events,
        private FeedIds $ids,
    ) {
    }

    /**
     * After a step raised the user's level. One entry per step, with the level reached: also when the
     * step passed two levels.
     */
    public function levelReached(UserId $userId, int $level, DateTimeImmutable $now): void
    {
        $this->write($userId, FeedEventType::LevelUp, (string) $level, null, $now);
    }

    /**
     * After a badge was stored for the first time.
     */
    public function badgeEarned(UserId $userId, Badge $badge, DateTimeImmutable $now): void
    {
        $this->write($userId, FeedEventType::BadgeEarned, $badge->value, null, $now);
    }

    /**
     * After a health milestone was stored for the first time. Who sees it is decided when the feed
     * is read, by the user's setting at that moment (ShowFeed).
     */
    public function milestoneReached(UserId $userId, HealthMilestone $milestone, DateTimeImmutable $now): void
    {
        $this->write($userId, FeedEventType::HealthMilestone, $milestone->value, null, $now);
    }

    /**
     * After a duel was stored. Only a decided duel writes something: for the winner, or for both in a
     * draw, and only for a side with a reduction above zero (HLD §4.8 "Score"). A lost duel is not
     * announced.
     */
    public function duelStored(Duel $duel): void
    {
        $decision = $duel->decision;
        if ($decision === null) {
            return;
        }
        foreach ([true, false] as $isChallenger) {
            $side = $decision->sideOf($isChallenger);
            if ($side === null || $side->actualTenths >= $side->expectedTenths) {
                continue;
            }
            $userId = $isChallenger ? $duel->challengerId : $duel->opponentId;
            // The eraser empties a side together with its id.
            \assert($userId !== null);
            $type = match ($decision->winner->resultFor($isChallenger)) {
                DuelResult::Won => FeedEventType::DuelWon,
                DuelResult::Draw => FeedEventType::DuelDraw,
                // Lost: nobody is told. A decided duel is never forfeited.
                default => null,
            };
            if ($type !== null) {
                $this->write($userId, $type, null, $isChallenger ? $duel->opponentId : $duel->challengerId, $decision->decidedAt);
            }
        }
    }

    private function write(UserId $userId, FeedEventType $type, ?string $detail, ?UserId $otherUserId, DateTimeImmutable $now): void
    {
        $this->events->append(new FeedEvent($this->ids->next(), $userId, $type, $detail, $otherUserId, $now));
    }
}
