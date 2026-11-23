<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Application\Day\XpLedger;
use SmokeDetector\Application\Friends\LockedPair;
use SmokeDetector\Application\IdGenerator;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Domain\Competition\CompetitionScore;
use SmokeDetector\Domain\Competition\CompetitionScoreComparator;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Level\LevelCalculator;
use SmokeDetector\Domain\Xp\DuelResult;
use SmokeDetector\Domain\Xp\XpCalculator;
use Throwable;

/**
 * php bin/console competitions:finalize, a step of the cron job after days:finalize (HLD §4.8 "Cron
 * step", §5.2): lets proposals expire after 72 hours, and decides the running duels whose last day
 * is final for both users.
 *
 * - Per run at most BATCH_SIZE proposals and BATCH_SIZE duels, proposals first. The rest follows in
 *   the next run.
 * - Every item is one transaction that locks both accounts first (LockedPair) and reads the duel
 *   again: one that was answered, taken back, cancelled or decided in the meantime is left alone.
 * - An item that fails is rolled back and logged without any id, and the run goes on. The report
 *   counts it, so the command fails.
 */
final readonly class DecideDuels
{
    /** How many proposals, and how many duels, one run takes at most. */
    public const int BATCH_SIZE = 50;

    private DuelSides $sides;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private Duels $duels,
        Calendars $calendars,
        DayRecords $records,
        private ProgressRecords $progress,
        private XpLedger $ledger,
        private IdGenerator $ids,
        private LoggerInterface $logger,
    ) {
        $this->sides = new DuelSides($calendars, $records);
    }

    public function execute(): DuelReport
    {
        $now = $this->clock->now();
        [$expired, $failedProposals] = $this->each($this->duels->expired($now, self::BATCH_SIZE), $this->expire(...));
        [$decided, $failedDuels] = $this->each($this->duels->decidable(self::BATCH_SIZE), fn (Duel $duel): Duel => $this->decide($duel, $now));

        return new DuelReport($expired, $decided, $failedProposals + $failedDuels);
    }

    /**
     * Does the work for every duel, each in its own transaction.
     *
     * @param list<Duel>           $duels
     * @param callable(Duel): Duel $work The duel as it is to be stored.
     *
     * @return array{int, int} How many were done, and how many failed.
     */
    private function each(array $duels, callable $work): array
    {
        $done = 0;
        $failed = 0;
        foreach ($duels as $duel) {
            try {
                $done += $this->transactions->run(function () use ($duel, $work): int {
                    if (!$this->isUnchangedAfterLocks($duel)) {
                        return 0;
                    }
                    $this->duels->save($work($duel));

                    return 1;
                });
            } catch (Throwable $failure) {
                // The class only: the message of a failure may hold a value of a row.
                $this->logger->error('A duel could not be decided.', ['exception' => $failure::class]);
                $failed++;
            }
        }

        return [$done, $failed];
    }

    /**
     * Locks both accounts, the smaller id first, and reads the duel again.
     */
    private function isUnchangedAfterLocks(Duel $duel): bool
    {
        try {
            LockedPair::lock($this->users, self::idOf($duel->challengerId), self::idOf($duel->opponentId));
        } catch (AccountNotFound) {
            // Deleted while the step waited: the deletion has taken care of the duel.
            return false;
        }

        return $this->duels->find($duel->id) == $duel;
    }

    private function expire(Duel $proposal): Duel
    {
        return $proposal->withStatus(DuelStatus::Expired);
    }

    /**
     * Scores both sides over all their days, finds the winner and pays the XP (HLD §4.8 "Score").
     */
    private function decide(Duel $duel, DateTimeImmutable $now): Duel
    {
        $challengerId = self::idOf($duel->challengerId);
        $opponentId = self::idOf($duel->opponentId);
        $challenger = $this->sideOf($challengerId, $duel->challengerStart, $duel->periodDays);
        $opponent = $this->sideOf($opponentId, $duel->opponentStart, $duel->periodDays);
        $winner = DuelWinner::of((new CompetitionScoreComparator())->compare(self::scoreOf($challenger), self::scoreOf($opponent)));

        return $duel->decided(new DuelDecision(
            $now,
            $winner,
            $this->settle($challengerId, $challenger, $winner->resultFor(true), $now),
            $this->settle($opponentId, $opponent, $winner->resultFor(false), $now),
            $challenger->calendar->rules->version,
        ));
    }

    private function sideOf(UserId $userId, ?LocalDate $start, int $periodDays): SideDays
    {
        // Accepting writes the first day of both sides.
        \assert($start !== null);

        return $this->sides->whole($userId, $start, $periodDays);
    }

    private static function scoreOf(SideDays $side): CompetitionScore
    {
        $score = $side->score();
        // Every day of a duel that is over counts, and a duel has 7 days at least.
        \assert($score !== null);

        return $score;
    }

    /**
     * Pays the XP of one side: to the ledger with the user's own local date of now, and to the
     * progress with the level calculated again. A reward of 0 writes nothing.
     *
     * @return SideResult The side as it is stored.
     */
    private function settle(UserId $userId, SideDays $side, DuelResult $result, DateTimeImmutable $now): SideResult
    {
        $rules = $side->calendar->rules;
        $award = (new XpCalculator($rules->xp))->forDuel($result);
        if ($award !== null) {
            $this->ledger->append(new XpEvent($this->ids->next(), $userId, $side->calendar->today($now), $award->source, $award->amount, $award->detail, $now));
            $stored = $this->progress->find($userId);
            // The onboarding writes the plan and the progress in one transaction.
            \assert($stored !== null);
            $totalXp = $stored->progress->totalXp + $award->amount;
            $this->progress->save(new ProgressRecord(
                $userId,
                new UserProgress($stored->progress->streaks, $totalXp),
                (new LevelCalculator($rules->level))->levelFor($totalXp),
                $stored->nextDay,
                $stored->finalizeDueAt,
                $stored->baselineDueAt,
            ));
        }
        $score = self::scoreOf($side);

        return new SideResult(
            $side->baseline->tenths,
            $score->reduction->actualTenths,
            $score->reduction->expectedTenths,
            $score->smokeFreeDays,
            $score->checkIns,
            $award->amount ?? 0,
        );
    }

    private static function idOf(?UserId $userId): UserId
    {
        // The eraser turns the running duels of a deleted account into forfeits, and deletes its proposals.
        \assert($userId !== null);

        return $userId;
    }
}
