<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;

/**
 * A duel between two friends (HLD §4.3 "duels", §4.8): proposed by the challenger, answered by the
 * opponent, and decided over the same number of days in the calendar of each of the two.
 *
 * The id of a side is null once that account is deleted, and so are its first day and its result.
 */
final readonly class Duel
{
    /** FR-COMP-01: one week, one month, three months or six months. */
    public const array PERIODS = [7, 30, 90, 180];

    /** FR-COMP-01: how long a proposal waits for its answer. */
    public const int OPEN_FOR_HOURS = 72;

    public function __construct(
        /** A UUID. */
        public string $id,
        public ?UserId $challengerId,
        public ?UserId $opponentId,
        public int $periodDays,
        public DuelStatus $status,
        public DateTimeImmutable $createdAt,
        /** Until when the proposal can be accepted: OPEN_FOR_HOURS after it was made. */
        public DateTimeImmutable $expiresAt,
        /** When the opponent accepted or declined. */
        public ?DateTimeImmutable $answeredAt = null,
        /** The first day of the duel in the challenger's calendar. Null before it is accepted. */
        public ?LocalDate $challengerStart = null,
        /** The first day of the duel in the opponent's calendar. */
        public ?LocalDate $opponentStart = null,
        /** Null until the duel is decided. */
        public ?DuelDecision $decision = null,
    ) {
        if (!in_array($periodDays, self::PERIODS, true)) {
            throw new InvalidArgumentException('A duel runs for 7, 30, 90 or 180 days.');
        }
    }

    /**
     * A new proposal of $challenger to $opponent, made at $now.
     */
    public static function proposal(string $id, UserId $challenger, UserId $opponent, int $periodDays, DateTimeImmutable $now): self
    {
        return new self($id, $challenger, $opponent, $periodDays, DuelStatus::Proposed, $now, $now->add(new DateInterval('PT' . self::OPEN_FOR_HOURS . 'H')));
    }

    /**
     * Whether the user is one of the two.
     */
    public function isOf(UserId $userId): bool
    {
        return $this->isChallenger($userId) || ($this->opponentId?->equals($userId) ?? false);
    }

    public function isChallenger(UserId $userId): bool
    {
        return $this->challengerId?->equals($userId) ?? false;
    }

    /**
     * The other one of the two, seen from $userId. Null when that account is gone.
     */
    public function otherThan(UserId $userId): ?UserId
    {
        return $this->isChallenger($userId) ? $this->opponentId : $this->challengerId;
    }

    /**
     * The first day of the duel in the calendar of $userId, one of the two.
     */
    public function startOf(UserId $userId): ?LocalDate
    {
        return $this->isChallenger($userId) ? $this->challengerStart : $this->opponentStart;
    }

    /**
     * Whether the proposal can still be answered or taken back at $now. Decided by the clock: a
     * proposal whose time is over is expired before the cron step has written it down.
     */
    public function isOpenAt(DateTimeImmutable $now): bool
    {
        return $this->status === DuelStatus::Proposed && $this->expiresAt > $now;
    }

    /**
     * The status as it is at $now: a proposal whose time is over is expired.
     */
    public function statusAt(DateTimeImmutable $now): DuelStatus
    {
        return $this->status === DuelStatus::Proposed && !$this->isOpenAt($now) ? DuelStatus::Expired : $this->status;
    }

    /**
     * Whether the duel takes one of the open places of $userId (FR-COMP-05): it runs, or it is an
     * own proposal whose time is not over.
     */
    public function takesPlaceOf(UserId $userId, DateTimeImmutable $now): bool
    {
        return $this->status === DuelStatus::Active || ($this->isOpenAt($now) && $this->isChallenger($userId));
    }

    /**
     * The same duel with another status and nothing else changed.
     */
    public function withStatus(DuelStatus $status): self
    {
        return new self(
            $this->id, $this->challengerId, $this->opponentId, $this->periodDays, $status, $this->createdAt, $this->expiresAt,
            $this->answeredAt, $this->challengerStart, $this->opponentStart, $this->decision,
        );
    }

    /**
     * The proposal, declined at $at.
     */
    public function declinedAt(DateTimeImmutable $at): self
    {
        return new self($this->id, $this->challengerId, $this->opponentId, $this->periodDays, DuelStatus::Declined, $this->createdAt, $this->expiresAt, $at);
    }

    /**
     * The proposal, accepted at $at: it starts on these days of the two calendars.
     */
    public function acceptedAt(DateTimeImmutable $at, LocalDate $challengerStart, LocalDate $opponentStart): self
    {
        return new self(
            $this->id, $this->challengerId, $this->opponentId, $this->periodDays, DuelStatus::Active, $this->createdAt, $this->expiresAt,
            $at, $challengerStart, $opponentStart,
        );
    }

    /**
     * The running duel, decided.
     */
    public function decided(DuelDecision $decision): self
    {
        return new self(
            $this->id, $this->challengerId, $this->opponentId, $this->periodDays, DuelStatus::Completed, $this->createdAt, $this->expiresAt,
            $this->answeredAt, $this->challengerStart, $this->opponentStart, $decision,
        );
    }
}
