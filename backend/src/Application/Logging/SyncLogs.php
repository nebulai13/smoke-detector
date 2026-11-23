<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Logging;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Day\Calendar;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /logs/sync (HLD §4.6, FR-LOG-02, -03, -07, NFR-03): logs and deletions from the outbox of
 * an app, applied in order, each answered on its own.
 *
 * The rules are checked per item in this order, and the first that applies answers it:
 * 1. the id belongs to another user: not_yours;
 * 2. the log is deleted: accepted, nothing changes (a deleted log stays deleted);
 * 3. the log is stored exactly as sent: accepted, nothing changes (the app may have missed the
 *    first answer, also when the day has become final since);
 * 4. more than FUTURE_TOLERANCE_SECONDS ahead of the server's clock: in_future;
 * 5. before the first day of the plan: before_start;
 * 6. the day of the log, or the day it would leave, is final or past its 48 hours: day_finalized;
 * 7. the day would hold more than MAX_SESSIONS_PER_DAY: day_full.
 *
 * The days that accepted items touch are counted again from their logs in the same transaction,
 * and come back in the answer, oldest first, also when nothing changed.
 */
final readonly class SyncLogs
{
    /** The most items one request may carry. */
    public const int MAX_ITEMS = 200;

    /** The most sessions one day can hold (SRS OQ-9). */
    public const int MAX_SESSIONS_PER_DAY = 300;

    /** How far a log may lie ahead of the server's clock: the clocks of phones are not exact. */
    public const int FUTURE_TOLERANCE_SECONDS = 300;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private Calendars $calendars,
        private SmokeLogs $logs,
        private DayRecords $days,
        private ProgressRecords $progress,
    ) {
    }

    /**
     * @param list<LogItem> $items
     *
     * @throws InvalidArgumentException With more than MAX_ITEMS items: the action refuses that first.
     * @throws PlanRequired             Before the onboarding.
     * @throws AccountNotFound          When the account was deleted in the meantime.
     */
    public function execute(UserId $userId, array $items): SyncResult
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('A sync holds at most ' . self::MAX_ITEMS . ' items.');
        }
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $items, $now): SyncResult {
            // The lock comes first: no other read of this transaction may come before it (HLD §4.5
            // "Transactions and locks"). Two syncs of one user take turns.
            $this->users->lock($userId) ?? throw new AccountNotFound();
            $calendar = $this->calendars->of($userId) ?? throw new PlanRequired();
            $stored = $this->progress->find($userId) ?? throw new LogicException('A plan exists without progress.');

            $results = [];
            $touched = [];
            foreach ($items as $item) {
                [$rejection, $dates] = $this->apply($userId, $item, $calendar, $stored, $now);
                $results[] = new ItemResult($item->id, $rejection);
                foreach ($dates as $date) {
                    $touched[$date->value] = $date;
                }
            }

            // "YYYY-MM-DD" sorts as the dates do.
            ksort($touched, SORT_STRING);
            $days = [];
            foreach ($touched as $date) {
                $days[] = $calendar->dayView($date, $this->days->find($userId, $date), $stored->nextDay);
            }

            return new SyncResult($results, $days);
        });
    }

    /**
     * @return array{Rejection|null, list<LocalDate>} Why the item was not stored, or the days it
     *                                                belongs to when it was accepted.
     */
    private function apply(UserId $userId, LogItem $item, Calendar $calendar, ProgressRecord $stored, DateTimeImmutable $now): array
    {
        $log = $this->logs->find($item->id);
        if ($log !== null && !$log->userId->equals($userId)) {
            return [Rejection::NotYours, []];
        }
        if ($log?->deletedAt !== null) {
            return [null, [$log->localDate]];
        }
        $entry = $item->entry;
        if ($entry === null) {
            return $this->delete($log, $calendar, $stored, $now);
        }
        $smokedAt = $entry->smokedAt;
        $count = $entry->count;
        // Instants compare by value: the same moment in another zone object is the same moment.
        if ($log !== null && $log->smokedAt == $smokedAt && $log->productType === $entry->productType && $log->count === $count) {
            return [null, [$log->localDate]];
        }

        $date = $calendar->days->dateOf($smokedAt);
        if ($smokedAt->getTimestamp() - $now->getTimestamp() > self::FUTURE_TOLERANCE_SECONDS) {
            return [Rejection::InFuture, []];
        }
        if ($date->isBefore($calendar->plan->startDate)) {
            return [Rejection::BeforeStart, []];
        }
        if ($calendar->isClosed($date, $stored->nextDay, $now) || ($log !== null && $calendar->isClosed($log->localDate, $stored->nextDay, $now))) {
            return [Rejection::DayFinalized, []];
        }
        // The log's own sessions are not counted twice when it stays on its day.
        $others = $this->logs->sessionsOn($userId, $date) - ($log !== null && $log->localDate->equals($date) ? $log->count : 0);
        if ($others + $count > self::MAX_SESSIONS_PER_DAY) {
            return [Rejection::DayFull, []];
        }

        $saved = $this->logs->save(new SmokeLog(
            $item->id,
            $userId,
            $smokedAt,
            $date,
            $entry->productType,
            $count,
            // The repository keeps when the log was first stored.
            $now,
            $now,
        ));
        // Another user stored the id at the same moment: a log never changes its owner.
        if (!$saved) {
            return [Rejection::NotYours, []];
        }

        $dates = $log === null || $log->localDate->equals($date) ? [$date] : [$log->localDate, $date];
        foreach ($dates as $touched) {
            $this->recount($userId, $touched);
        }

        return [null, $dates];
    }

    /**
     * @return array{Rejection|null, list<LocalDate>}
     */
    private function delete(?SmokeLog $log, Calendar $calendar, ProgressRecord $stored, DateTimeImmutable $now): array
    {
        // A log the server never saw: there is nothing to delete, and nothing to keep either.
        if ($log === null) {
            return [null, []];
        }
        if ($calendar->isClosed($log->localDate, $stored->nextDay, $now)) {
            return [Rejection::DayFinalized, []];
        }

        $this->logs->save(new SmokeLog(
            $log->id,
            $log->userId,
            $log->smokedAt,
            $log->localDate,
            $log->productType,
            $log->count,
            $log->createdAt,
            $now,
            $now,
        ));
        $this->recount($log->userId, $log->localDate);

        return [null, [$log->localDate]];
    }

    /**
     * Counts the day again from its logs. The check-in and everything else of the day stay.
     */
    private function recount(UserId $userId, LocalDate $date): void
    {
        $day = $this->days->find($userId, $date);
        $this->days->save(new DayRecord($userId, $date, $this->logs->sessionsOn($userId, $date), $day?->checkedInAt, $day?->result));
    }
}
