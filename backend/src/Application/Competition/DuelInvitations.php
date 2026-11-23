<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Day\Calendar;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\FriendNotFound;
use SmokeDetector\Application\Friends\LockedPair;
use SmokeDetector\Application\IdGenerator;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /duels, /duels/accept, /duels/decline and /duels/cancel (FR-COMP-01, 02, 05; HLD §4.8
 * "Lifecycle", "Limits", "Locks"): a user with a plan challenges a friend, who has 72 hours to
 * accept or decline; until then the challenger can take the proposal back.
 *
 * Every operation locks both accounts first, the smaller id first (LockedPair), and judges the duel
 * as it is after the locks.
 */
final readonly class DuelInvitations
{
    /** FR-COMP-05: running duels and own open proposals of a user, together. */
    public const int MAX_OPEN = 5;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private Duels $duels,
        private Calendars $calendars,
        private IdGenerator $ids,
    ) {
    }

    /**
     * @return Duel The proposal.
     *
     * @throws AccountNotFound          The account of $userId was deleted in the meantime.
     * @throws FriendNotFound           $friend is not an accepted friend (or is $userId), or the
     *                                  account does not exist (any more).
     * @throws InvalidArgumentException The period is not one of Duel::PERIODS.
     * @throws PlanRequired             $userId has not done the onboarding.
     * @throws DuelExists               The two have an open duel already, whoever proposed it.
     * @throws DuelLimitReached         The open duels of $userId are MAX_OPEN already.
     */
    public function propose(UserId $userId, UserId $friend, int $periodDays): Duel
    {
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $friend, $periodDays, $now): Duel {
            $account = LockedPair::lock($this->users, $userId, $friend);
            if ($account === null || !($this->links->between($userId, $friend)?->isAccepted() ?? false)) {
                throw new FriendNotFound();
            }
            $proposal = Duel::proposal($this->ids->next(), $userId, $friend, $periodDays, $now);
            $this->planOf($userId);

            $open = [];
            foreach ($this->duels->openOf($userId) as $duel) {
                if (!$duel->isOf($friend)) {
                    $open[] = $duel;
                } elseif ($duel->isOpenAt($now) || $duel->status === DuelStatus::Active) {
                    throw new DuelExists();
                } else {
                    // Expired by the clock, and the cron step has not been there yet: written down now.
                    $this->duels->save($duel->withStatus(DuelStatus::Expired));
                }
            }
            $this->assertRoom($userId, $open, $now);
            $this->duels->save($proposal);

            return $proposal;
        });
    }

    /**
     * $userId accepts the proposal. It starts for each of the two on their own next local day.
     *
     * Refused, after the checks below, with PlanRequired when $userId has not done the onboarding,
     * and with DuelLimitReached when the open duels of $userId are MAX_OPEN already.
     *
     * @return Duel The running duel.
     *
     * @throws DuelNotFound    $userId is not one of the two, or there is no duel with this id.
     * @throws DuelNotOpen     $userId is the challenger, the duel is not a proposal, or its time is over.
     * @throws AccountNotFound The account of $userId was deleted in the meantime.
     */
    public function accept(UserId $userId, string $duelId): Duel
    {
        return $this->answer($userId, $duelId, false, function (Duel $proposal, DateTimeImmutable $now) use ($userId): Duel {
            $calendar = $this->planOf($userId);
            $this->assertRoom($userId, $this->duels->openOf($userId), $now);

            // A proposal is deleted with the account of either of the two, and proposing needs a plan.
            $challengerId = $proposal->challengerId;
            \assert($challengerId !== null);
            $challenger = $this->calendars->of($challengerId);
            \assert($challenger !== null);
            $accepted = $proposal->acceptedAt($now, $challenger->today($now)->plusDays(1), $calendar->today($now)->plusDays(1));
            $this->duels->save($accepted);

            return $accepted;
        });
    }

    /**
     * $userId declines the proposal. It stays in both lists as declined.
     *
     * @throws DuelNotFound    $userId is not one of the two, or there is no duel with this id.
     * @throws DuelNotOpen     $userId is the challenger, the duel is not a proposal, or its time is over.
     * @throws AccountNotFound The account of $userId was deleted in the meantime.
     */
    public function decline(UserId $userId, string $duelId): void
    {
        $this->answer($userId, $duelId, false, function (Duel $proposal, DateTimeImmutable $now): Duel {
            $declined = $proposal->declinedAt($now);
            $this->duels->save($declined);

            return $declined;
        });
    }

    /**
     * The challenger takes the proposal back. It is deleted.
     *
     * @throws DuelNotFound    $userId is not one of the two, or there is no duel with this id.
     * @throws DuelNotOpen     $userId is the one who was challenged, the duel is not a proposal, or
     *                         its time is over.
     * @throws AccountNotFound The account of $userId was deleted in the meantime.
     */
    public function cancel(UserId $userId, string $duelId): void
    {
        $this->answer($userId, $duelId, true, function (Duel $proposal): Duel {
            $this->duels->delete($proposal->id);

            return $proposal;
        });
    }

    /**
     * Reads the duel to learn the other user, locks both accounts, and reads it again: it is judged
     * as it is after the wait, and $change is made to it.
     *
     * A duel that is gone after the wait is refused after the transaction: what happened while the
     * locks were awaited stays as it is.
     *
     * @param bool                                  $asChallenger Whether $userId must be the challenger
     *                                                            (to take it back) or the one who was
     *                                                            challenged (to answer).
     * @param callable(Duel, DateTimeImmutable): Duel $change
     *
     * @throws DuelNotFound
     * @throws DuelNotOpen
     * @throws AccountNotFound
     */
    private function answer(UserId $userId, string $duelId, bool $asChallenger, callable $change): Duel
    {
        $now = $this->clock->now();

        $changed = $this->transactions->run(function () use ($userId, $duelId, $asChallenger, $change, $now): ?Duel {
            $before = $this->duelOf($userId, $duelId) ?? throw new DuelNotFound();
            // An account that is gone has no open proposal: the eraser deletes them. Then only the
            // user's own account is locked.
            LockedPair::lock($this->users, $userId, $before->otherThan($userId) ?? $userId);

            // Who the two are never changes while both accounts exist.
            $duel = $this->duels->find($duelId);
            if ($duel === null) {
                return null;
            }
            if ($duel->isChallenger($userId) !== $asChallenger || !$duel->isOpenAt($now)) {
                throw new DuelNotOpen();
            }

            return $change($duel, $now);
        });

        return $changed ?? throw new DuelNotFound();
    }

    /**
     * The duel, when $userId is one of the two.
     */
    private function duelOf(UserId $userId, string $duelId): ?Duel
    {
        $duel = $this->duels->find($duelId);

        return $duel !== null && $duel->isOf($userId) ? $duel : null;
    }

    /**
     * @throws PlanRequired
     */
    private function planOf(UserId $userId): Calendar
    {
        return $this->calendars->of($userId) ?? throw new PlanRequired();
    }

    /**
     * The limit (FR-COMP-05, HLD §4.8 "Limits"): running duels and own proposals whose time is not
     * over stay below MAX_OPEN. Proposals of others do not count until they are accepted, or friends
     * could fill somebody's list.
     *
     * @param list<Duel> $open Open duels of $userId.
     *
     * @throws DuelLimitReached
     */
    private function assertRoom(UserId $userId, array $open, DateTimeImmutable $now): void
    {
        $taken = array_filter($open, static fn (Duel $duel): bool => $duel->takesPlaceOf($userId, $now));
        if (count($taken) >= self::MAX_OPEN) {
            throw new DuelLimitReached();
        }
    }
}
