<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateInterval;
use DateTimeImmutable;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Badge\AwardBadges;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Day\XpLedger;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\IdGenerator;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Domain\Competition\CompetitionScoreComparator;
use SmokeDetector\Domain\Xp\XpCalculator;
use Throwable;

/**
 * Ranks the stored league results, a part of `competitions:finalize` (HLD §4.9 "Rank and reward",
 * SRS FR-COMP-08, OQ-8).
 *
 * - A result is ranked when every friend of its user is settled for the period: the friend has a
 *   stored result, or cannot get one (no plan, or a plan that started after the period). Otherwise
 *   it is looked at again RECHECK_MINUTES later, and after MAX_WAIT_DAYS it is ranked with the
 *   results that are there.
 * - The rank is 1 plus the number of participants (the user and the friends with a result) who are
 *   better by CompetitionScoreComparator. The XP of the rank is paid as the XP of a duel; it and the
 *   champion badge are given only for a result with a reduction above zero.
 * - Per run at most BATCH_SIZE results. Each is one transaction that locks the account of its user
 *   and reads the result again: one that changed in the meantime (ranked by another run) or whose
 *   account is gone is left alone.
 * - A result that fails is rolled back and logged without any id, looked at again RECHECK_MINUTES
 *   later, and the run goes on. The report counts it, so the command fails.
 */
final readonly class RankLeagues
{
    /** How many results one run looks at, at most. */
    public const int BATCH_SIZE = 50;

    /** How long a result that waits for a friend's result waits until it is looked at again. */
    public const int RECHECK_MINUTES = 15;

    /** After this long from its scoring, a result is ranked with the results that are there. */
    public const int MAX_WAIT_DAYS = 7;

    private CompetitionRewards $rewards;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private LeagueResults $results,
        private Calendars $calendars,
        ProgressRecords $progress,
        XpLedger $ledger,
        IdGenerator $ids,
        private LoggerInterface $logger,
        private AwardBadges $badges,
    ) {
        $this->rewards = new CompetitionRewards($progress, $ledger, $ids);
    }

    public function execute(): LeagueReport
    {
        $now = $this->clock->now();
        $ranked = 0;
        $waiting = 0;
        $failed = 0;
        foreach ($this->results->unranked($now, self::BATCH_SIZE) as $result) {
            try {
                [$isRanked, $isWaiting] = $this->transactions->run(fn (): array => $this->rank($result, $now));
                $ranked += $isRanked;
                $waiting += $isWaiting;
            } catch (Throwable $failure) {
                $this->recheckLater($result, $now);
                // The class only: the message of a failure may hold a value of a row.
                $this->logger->error('A league result could not be ranked.', ['exception' => $failure::class]);
                $failed++;
            }
        }

        return new LeagueReport($ranked, $waiting, $failed);
    }

    /**
     * @return array{int, int} 1 and 0 for a result that was ranked, 0 and 1 for one that waits, and
     *                         0 and 0 for one that was left alone.
     */
    private function rank(LeagueResult $result, DateTimeImmutable $now): array
    {
        $userId = $result->userId;
        if ($this->users->lock($userId) === null || $this->results->find($userId, $result->period) != $result) {
            return [0, 0];
        }

        $score = $result->score();
        $comparator = new CompetitionScoreComparator();
        $participants = 1;
        $better = 0;
        $settled = true;
        foreach (LeagueFriends::of($this->links, $userId) as $friendId) {
            $theirs = $this->results->find($friendId, $result->period);
            if ($theirs === null) {
                if (!$this->cannotHaveResult($friendId, $result->period)) {
                    $settled = false;
                }
                continue;
            }
            $participants++;
            $better += $comparator->compare($theirs->score(), $score) > 0 ? 1 : 0;
        }

        if (!$settled && $result->scoredAt->add(new DateInterval('P' . self::MAX_WAIT_DAYS . 'D')) > $now) {
            $this->results->save($result->checkedAgainAt(self::recheckAt($now)));

            return [0, 1];
        }

        $calendar = $this->calendars->of($userId) ?? throw new LogicException('A league result exists without a plan.');
        $rank = 1 + $better;
        // A rank is rewarded only for a reduction: otherwise three friends who all do nothing would
        // share rank 1 and its reward (HLD §4.9).
        $hasReduction = $score->reduction->savedTenths() > 0;
        $xp = 0;
        if ($hasReduction) {
            $award = (new XpCalculator($calendar->rules->xp))->forLeague($rank, $result->period->type, $participants);
            $xp = $this->rewards->pay($userId, $calendar, $award, $now);
        }
        $this->results->save($result->ranked(new LeagueAward($rank, $participants, $xp, $now)));
        if ($hasReduction) {
            // The champion badge (HLD §4.10), in the same transaction as the rank.
            $this->badges->leagueRanked($userId, $result->period->type, $rank, $participants, $calendar, $now);
        }

        return [1, 0];
    }

    /**
     * After a failure: the result is looked at again RECHECK_MINUTES later, so that one that fails
     * every time is not the first of every run. Saving keeps what was scored and a rank that was
     * decided. If even this fails, it is logged and the run goes on.
     */
    private function recheckLater(LeagueResult $result, DateTimeImmutable $now): void
    {
        try {
            $this->transactions->run(fn () => $this->results->save($result->checkedAgainAt(self::recheckAt($now))));
        } catch (Throwable $failure) {
            $this->logger->error('A league result that could not be ranked could not be put back either.', ['exception' => $failure::class]);
        }
    }

    private static function recheckAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->add(new DateInterval('PT' . self::RECHECK_MINUTES . 'M'));
    }

    /**
     * Whether the friend will never have a result for the period: no plan, or a plan that started
     * after its last day.
     */
    private function cannotHaveResult(UserId $friendId, CalendarPeriod $period): bool
    {
        return $this->calendars->of($friendId)?->plan->startDate->isAfter($period->last()) ?? true;
    }
}
