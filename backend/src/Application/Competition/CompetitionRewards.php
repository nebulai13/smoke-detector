<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\Calendar;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Application\Day\XpLedger;
use SmokeDetector\Application\IdGenerator;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Level\LevelCalculator;
use SmokeDetector\Domain\Xp\XpAward;

/**
 * Pays the XP of a decided duel or a ranked league result (HLD §4.8 "Score", §4.9 "Rank and
 * reward"): to the ledger with the user's own local date of now, and to the progress with the
 * level calculated again, inside the caller's transaction. A reward of nothing writes nothing.
 */
final readonly class CompetitionRewards
{
    public function __construct(
        private ProgressRecords $progress,
        private XpLedger $ledger,
        private IdGenerator $ids,
    ) {
    }

    /**
     * @param Calendar $calendar The calendar of the user: their today and the rules in force.
     *
     * @return int The XP paid: 0 without an award.
     */
    public function pay(UserId $userId, Calendar $calendar, ?XpAward $award, DateTimeImmutable $now): int
    {
        if ($award === null) {
            return 0;
        }
        $this->ledger->append(new XpEvent($this->ids->next(), $userId, $calendar->today($now), $award->source, $award->amount, $award->detail, $now));
        $stored = $this->progress->find($userId);
        // The onboarding writes the plan and the progress in one transaction.
        \assert($stored !== null);
        $totalXp = $stored->progress->totalXp + $award->amount;
        $this->progress->save(new ProgressRecord(
            $userId,
            new UserProgress($stored->progress->streaks, $totalXp),
            (new LevelCalculator($calendar->rules->level))->levelFor($totalXp),
            $stored->nextDay,
            $stored->finalizeDueAt,
            $stored->baselineDueAt,
        ));

        return $award->amount;
    }
}
