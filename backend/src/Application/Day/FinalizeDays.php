<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use DateInterval;
use DateTimeImmutable;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Badge\AwardBadges;
use SmokeDetector\Application\Competition\LeagueScores;
use SmokeDetector\Application\Feed\PublishToFeed;
use SmokeDetector\Application\IdGenerator;
use SmokeDetector\Application\Logging\SmokeLogs;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\BaselineHistory;
use SmokeDetector\Application\Recovery\AwardMilestones;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Domain\Day\DayEventType;
use SmokeDetector\Domain\Day\DayFinalizer;
use SmokeDetector\Domain\Plan\BaselineCalculator;
use Throwable;

/**
 * php bin/console days:finalize, a step of the cron job (HLD §4.6 "Day finalization", §5.2): makes
 * the days final 48 hours after they ended, and calculates the weekly baselines.
 *
 * - The users that are due come from user_progress, the one who waits longest first, BATCH_SIZE at a
 *   time. A user is served once per run: until nothing more is due, the run is out of time, or a
 *   step fails.
 * - Every step is one transaction that locks the account first and then reads the progress again,
 *   so two runs at once take turns and the second finds the work done. What is due is worked out
 *   from the zone history, not taken from the stored instants. A weekly baseline that is due no
 *   later than the next day goes first, so a day is always final with the baseline valid on it.
 * - No step is begun once BUDGET_SECONDS have passed since the run began. What is left is due at
 *   the next run.
 * - A step that fails is rolled back and logged without naming the user, and the run goes on with
 *   the next user. The report counts the user, so the command fails.
 */
final readonly class FinalizeDays
{
    /** How long a run begins new steps: its share of the cron minute. */
    public const int BUDGET_SECONDS = 20;

    /** A day is final this long after it ended. */
    public const int GRACE_SECONDS = Schedule::FINAL_AFTER_HOURS * 3600;

    /** How many due users are read at a time. */
    public const int BATCH_SIZE = 50;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private Calendars $calendars,
        private SmokeLogs $logs,
        private DayRecords $days,
        private ProgressRecords $progress,
        private XpLedger $ledger,
        private BaselineHistory $baselines,
        private IdGenerator $ids,
        private LoggerInterface $logger,
        private LeagueScores $leagueScores,
        private AwardBadges $badges,
        private AwardMilestones $milestones,
        private PublishToFeed $feed,
    ) {
    }

    public function execute(): FinalizeReport
    {
        $deadline = $this->clock->now()->add(new DateInterval('PT' . self::BUDGET_SECONDS . 'S'));
        $served = [];
        $days = 0;
        $baselines = 0;
        $failedUsers = 0;

        while (true) {
            $fresh = array_filter(
                $this->progress->due($this->clock->now(), self::BATCH_SIZE),
                static fn (UserId $userId): bool => !isset($served[$userId->value]),
            );
            if ($fresh === []) {
                return new FinalizeReport($days, $baselines, $failedUsers, false);
            }

            foreach ($fresh as $userId) {
                $served[$userId->value] = $userId;
                try {
                    do {
                        $step = $this->transactions->run(fn (): FinalizeStep => $this->step($userId, $deadline));
                        $days += $step === FinalizeStep::DayFinalized ? 1 : 0;
                        $baselines += $step === FinalizeStep::BaselineCalculated ? 1 : 0;
                    } while ($step === FinalizeStep::DayFinalized || $step === FinalizeStep::BaselineCalculated);
                } catch (Throwable $failure) {
                    // The class only: the message of a failure may hold a value of a row.
                    $this->logger->error('The days of a user could not be finalized.', ['exception' => $failure::class]);
                    $failedUsers++;
                    $step = FinalizeStep::Failed;
                }

                if ($step === FinalizeStep::OutOfTime) {
                    return new FinalizeReport($days, $baselines, $failedUsers, true);
                }
            }
        }
    }

    /**
     * The next thing that is due for the user, inside a transaction.
     */
    private function step(UserId $userId, DateTimeImmutable $deadline): FinalizeStep
    {
        // The lock comes first (HLD §4.5 "Transactions and locks"). An account deleted in the
        // meantime took its progress with it.
        if ($this->users->lock($userId) === null) {
            return FinalizeStep::NothingDue;
        }
        $calendar = $this->calendars->of($userId) ?? throw new LogicException('Progress exists without a plan.');
        $stored = $this->progress->find($userId) ?? throw new LogicException('A plan exists without progress.');
        $now = $this->clock->now();

        $dayDueAt = Schedule::finalizeDueAt($calendar->days, $stored->nextDay);
        $baselineDue = $stored->baselineDueAt <= $now && $stored->baselineDueAt <= $dayDueAt;
        if (!$baselineDue && $dayDueAt > $now) {
            // The stored instant brought the user here too early: it is put right.
            $this->progress->save(new ProgressRecord(
                $userId,
                $stored->progress,
                $stored->level,
                $stored->nextDay,
                $dayDueAt,
                $stored->baselineDueAt,
            ));

            return FinalizeStep::NothingDue;
        }

        if ($now >= $deadline) {
            return FinalizeStep::OutOfTime;
        }

        if ($baselineDue) {
            $this->calculateBaseline($calendar, $stored);

            return FinalizeStep::BaselineCalculated;
        }

        $this->finalizeDay($calendar, $stored, $now);

        return FinalizeStep::DayFinalized;
    }

    private function finalizeDay(Calendar $calendar, ProgressRecord $stored, DateTimeImmutable $now): void
    {
        $userId = $stored->userId;
        $date = $stored->nextDay;
        $checkedInAt = $this->days->find($userId, $date)?->checkedInAt;
        // Counted from the logs once more, not taken from the stored count (NFR-04).
        $count = $this->logs->sessionsOn($userId, $date);
        $outcome = $calendar->outcomeOn($date, $count, $checkedInAt !== null);
        $finalization = (new DayFinalizer($calendar->rules))->finalize($stored->progress, $outcome);

        $this->days->save(new DayRecord($userId, $date, $count, $checkedInAt, new DayResult(
            $outcome->limit,
            $finalization->xpEarned(),
            $finalization->events,
            $finalization->eventsOfType(DayEventType::FreezeUsed) !== [],
            $now,
            $calendar->rules->version,
        )));
        // The last day of a league period stores the user's result for it (HLD §4.9 "Final scores").
        $this->leagueScores->dayFinalized($calendar, $date, $now);
        // The badges a final day justifies (HLD §4.10), in the same transaction.
        $this->badges->dayFinalized($calendar, $date, $outcome, $finalization->streaks, $now);
        // The health milestones reached by the end of the day, for friends (HLD §4.11), in the same transaction.
        $this->milestones->dayFinalized($calendar, $date, $now);
        foreach ($finalization->xpAwards as $award) {
            $this->ledger->append(new XpEvent($this->ids->next(), $userId, $date, $award->source, $award->amount, $award->detail, $now));
        }

        $next = $date->plusDays(1);
        $this->progress->save(new ProgressRecord(
            $userId,
            $finalization->progress(),
            $finalization->levelAfter,
            $next,
            Schedule::finalizeDueAt($calendar->days, $next),
            $stored->baselineDueAt,
        ));
        if ($finalization->levelAfter > $finalization->levelBefore) {
            // One entry with the level reached, also when the day passed two (HLD §4.12).
            $this->feed->levelReached($userId, $finalization->levelAfter, $now);
        }
    }

    /**
     * The weekly baseline of the user's Monday (FR-PLAN-04 to -06): from the days of the month
     * window before it, as they stand now. Days before the plan are left out.
     */
    private function calculateBaseline(Calendar $calendar, ProgressRecord $stored): void
    {
        $userId = $stored->userId;
        $rules = $calendar->rules->baseline;
        $monday = $calendar->days->dateOf($stored->baselineDueAt);
        $windowStart = $monday->plusDays(-$rules->monthDays);
        $from = $calendar->plan->startDate->isAfter($windowStart) ? $calendar->plan->startDate : $windowStart;
        $sunday = $monday->plusDays(-1);

        $byDate = [];
        foreach ($this->days->between($userId, $from, $sunday) as $record) {
            $byDate[$record->date->value] = $record;
        }
        $counts = [];
        for ($date = $from; !$date->isAfter($sunday); $date = $date->plusDays(1)) {
            $record = $byDate[$date->value] ?? null;
            // A day without a check-in has no count the baseline trusts.
            $counts[] = $record !== null && $record->isCheckedIn() ? $record->count : null;
        }

        $update = (new BaselineCalculator($rules))->weeklyUpdate($calendar->baselineOn($sunday)->value, $counts);
        $this->baselines->append(new BaselineEntry(
            $userId,
            $monday,
            $update->value,
            $update->source,
            $update->weekMean,
            $update->monthMean,
            $update->weekDays,
            $update->monthDays,
            $calendar->rules->version,
        ));

        $this->progress->save(new ProgressRecord(
            $userId,
            $stored->progress,
            $stored->level,
            $stored->nextDay,
            $stored->finalizeDueAt,
            // A matter of Mondays, whatever the rule table counts as the week of the window.
            $calendar->days->startOf($monday->plusDays(Schedule::DAYS_PER_WEEK)),
        ));
    }
}
