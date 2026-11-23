<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\TransactionRunner;

/**
 * GET /duels and POST /duels/show (FR-COMP-01, 03, 08, 09; FR-FRND-03; HLD §4.8 "What the opponent
 * sees"): the duels of a user, both sides in whole percent and each day as a level.
 *
 * - A running duel shows the days that count so far, each side in its own calendar.
 * - A completed duel shows the stored result, and its days against the stored frozen baselines.
 * - The opponent (name and side) is shown only while the two are friends and the account exists.
 *
 * Locks nothing: one transaction only so that everything is read from one snapshot.
 */
final readonly class ShowDuels
{
    /** The list holds the newest duels. Also the maxItems of DuelList in api/openapi.yaml. */
    public const int MAX_LISTED = 100;

    private DuelSides $sides;

    /**
     * @param ProgressRecords $progress Not read: what counts of a running duel follows from the
     *                                  calendar and the days alone (HLD §4.8 "Score"). Taken because
     *                                  the tests build the use case with it.
     */
    public function __construct( // @phpstan-ignore constructor.unusedParameter
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private Duels $duels,
        Calendars $calendars,
        DayRecords $records,
        ProgressRecords $progress,
    ) {
        $this->sides = new DuelSides($calendars, $records);
    }

    /**
     * @return list<DuelView> The newest MAX_LISTED duels of the user, the newest first.
     *
     * @throws AccountNotFound The account was deleted in the meantime.
     */
    public function list(UserId $userId): array
    {
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $now): array {
            $this->assertAccount($userId);

            return array_map(
                fn (Duel $duel): DuelView => $this->detail($userId, $duel, $now)->duel,
                $this->duels->ofUser($userId, self::MAX_LISTED),
            );
        });
    }

    /**
     * @throws DuelNotFound    The user is not one of the two, or there is no duel with this id.
     * @throws AccountNotFound The account was deleted in the meantime.
     */
    public function show(UserId $userId, string $duelId): DuelDetail
    {
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $duelId, $now): DuelDetail {
            $this->assertAccount($userId);
            $duel = $this->duels->find($duelId);
            if ($duel === null || !$duel->isOf($userId)) {
                throw new DuelNotFound();
            }

            return $this->detail($userId, $duel, $now);
        });
    }

    private function assertAccount(UserId $userId): void
    {
        $this->users->find($userId) ?? throw new AccountNotFound();
    }

    private function detail(UserId $userId, Duel $duel, DateTimeImmutable $now): DuelDetail
    {
        $isChallenger = $duel->isChallenger($userId);
        $status = $duel->statusAt($now);
        $start = $duel->startOf($userId);
        $opponent = $this->visibleOpponent($userId, $duel->otherThan($userId));

        [$mine, $myDays, $xp] = $this->side($duel, $status, $userId, $now);
        [$theirs, $theirDays] = $opponent === null ? [null, []] : $this->side($duel, $status, $opponent->userId, $now);
        $result = $status === DuelStatus::Completed ? $duel->decision : null;

        $days = [];
        if ($status === DuelStatus::Active || $result !== null) {
            for ($day = 1; $day <= $duel->periodDays; $day++) {
                $days[] = new DayStanding($day, $myDays[$day - 1] ?? null, $theirDays[$day - 1] ?? null);
            }
        }

        return new DuelDetail(
            new DuelView(
                $duel->id,
                $status,
                $duel->periodDays,
                $isChallenger,
                $opponent,
                $duel->createdAt,
                $duel->expiresAt,
                $start,
                $start?->plusDays($duel->periodDays - 1),
                $mine,
                $theirs,
                $result?->winner->resultFor($isChallenger),
                $xp,
                $status === DuelStatus::Active,
            ),
            $days,
        );
    }

    /**
     * The other one of the two, as far as the user may see them: only while the two are friends
     * and the account exists.
     */
    private function visibleOpponent(UserId $userId, ?UserId $otherId): ?PublicProfile
    {
        if ($otherId === null || !($this->links->between($userId, $otherId)?->isAccepted() ?? false)) {
            return null;
        }
        $other = $this->users->find($otherId);

        return $other === null ? null : PublicProfile::of($other);
    }

    /**
     * One side: its standing, the level of each day that counts (day 1 at index 0), and the XP the
     * side earned with a completed duel.
     *
     * @return array{SideStanding|null, list<DayLevel>, int|null}
     */
    private function side(Duel $duel, DuelStatus $status, UserId $userId, DateTimeImmutable $now): array
    {
        if ($status === DuelStatus::Active) {
            $days = $this->sides->soFar($userId, self::startOf($duel, $userId), $duel->periodDays, $now);
            $score = $days->score();

            return [$score === null ? null : SideStanding::ofScore($score), $days->levels(), null];
        }
        if ($status === DuelStatus::Completed) {
            $decision = $duel->decision;
            \assert($decision !== null);
            // Only the side of a deleted account is emptied, and that side is never shown.
            $result = $decision->sideOf($duel->isChallenger($userId));
            \assert($result !== null);
            $days = $this->sides->whole($userId, self::startOf($duel, $userId), $duel->periodDays, $result->b0Tenths);

            return [SideStanding::ofResult($result, $duel->periodDays), $days->levels(), $result->xp];
        }

        return [null, [], null];
    }

    private static function startOf(Duel $duel, UserId $userId): LocalDate
    {
        // Accepting writes the first day of both sides; only the side of a deleted account loses it.
        $start = $duel->startOf($userId);
        \assert($start !== null);

        return $start;
    }
}
