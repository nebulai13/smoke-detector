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
use SmokeDetector\Domain\Competition\CompetitionScoreComparator;

/**
 * GET /duels and POST /duels/show (FR-COMP-01, 03, 08, 09; FR-FRND-03; HLD §4.8 "What the opponent
 * sees"): the duels of a user, each day as a level.
 *
 * - A running duel shows, each side in its own calendar, the own side live (the days that count so
 *   far) in whole percent, and of the opponent's side the final days only, without a percentage. Who
 *   is ahead is decided over the final days of both (HLD §4.8 "Of the other side, final days only"):
 *   the user can still change the own open days, and could find the opponent's reduction by trying.
 * - A completed duel shows the stored result, both sides in whole percent, and its days against the stored frozen baselines.
 * - The opponent (name and side) is shown only while the two are friends and the account exists.
 *
 * Locks nothing: one transaction only so that everything is read from one snapshot.
 */
final readonly class ShowDuels
{
    /** The list holds the newest duels. Also the maxItems of DuelList in api/openapi.yaml. */
    public const int MAX_LISTED = 100;

    private DuelSides $sides;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private Duels $duels,
        Calendars $calendars,
        DayRecords $records,
        ProgressRecords $progress,
    ) {
        $this->sides = new DuelSides($calendars, $records, $progress);
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
        [$theirs, $theirDays, $standing] = $opponent === null
            ? [null, [], null]
            : $this->otherSide($duel, $status, $userId, $opponent->userId);
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
                $standing,
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
     * The user's own side: its standing, the level of each day that counts (day 1 at index 0), and
     * the XP the side earned with a completed duel. A running duel is shown live.
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
            [$result, $days] = $this->completed($duel, $userId);

            return [SideStanding::ofResult($result, $duel->periodDays), $days->levels(), $result->xp];
        }

        return [null, [], null];
    }

    /**
     * The opponent's side as the user may see it: its standing and the level of each day (day 1 at
     * index 0), and whether the user is ahead. Of a running duel the final days only, without a
     * percentage, and who is ahead over the final days of both, once both have one.
     *
     * @return array{SideStanding|null, list<DayLevel>, DuelStanding|null}
     */
    private function otherSide(Duel $duel, DuelStatus $status, UserId $userId, UserId $otherId): array
    {
        if ($status === DuelStatus::Active) {
            $days = $this->sides->final($otherId, self::startOf($duel, $otherId), $duel->periodDays);
            $theirs = $days->score();
            if ($theirs === null) {
                return [null, [], null];
            }
            $mine = $this->sides->final($userId, self::startOf($duel, $userId), $duel->periodDays)->score();
            $standing = $mine === null ? null : DuelStanding::of((new CompetitionScoreComparator())->compare($mine, $theirs));

            return [SideStanding::ofOpponentScore($theirs), $days->levels(), $standing];
        }
        if ($status === DuelStatus::Completed) {
            [$result, $days] = $this->completed($duel, $otherId);

            return [SideStanding::ofResult($result, $duel->periodDays), $days->levels(), null];
        }

        return [null, [], null];
    }

    /**
     * One side of a completed duel: its stored result and its days against the stored baseline.
     *
     * @return array{SideResult, SideDays}
     */
    private function completed(Duel $duel, UserId $userId): array
    {
        $decision = $duel->decision;
        \assert($decision !== null);
        // Only the side of a deleted account is emptied, and that side is never shown.
        $result = $decision->sideOf($duel->isChallenger($userId));
        \assert($result !== null);

        return [$result, $this->sides->whole($userId, self::startOf($duel, $userId), $duel->periodDays, $result->b0Tenths)];
    }

    private static function startOf(Duel $duel, UserId $userId): LocalDate
    {
        // Accepting writes the first day of both sides; only the side of a deleted account loses it.
        $start = $duel->startOf($userId);
        \assert($start !== null);

        return $start;
    }
}
