<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use LogicException;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Logging\Rejection;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /days/{date}/check-in (HLD §4.6, FR-LOG-04): "Done for today".
 *
 * Possible for today and every earlier day that is not final. The time of the first check-in is
 * stored; a second one changes nothing. The answer is the day, what it gives to celebrate and the
 * progress, all from the projection (FR-GAME-06): stored progress changes only at finalization.
 */
final readonly class CheckIn
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private Calendars $calendars,
        private DayRecords $days,
        private ProgressRecords $progress,
    ) {
    }

    /**
     * @throws DayNotOpen      When the day is before the plan, final, or has not begun for the user.
     * @throws PlanRequired    Before the onboarding.
     * @throws AccountNotFound When the account was deleted in the meantime.
     */
    public function execute(UserId $userId, LocalDate $date): CheckInResult
    {
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $date, $now): CheckInResult {
            // The lock comes first: no other read of this transaction may come before it (HLD §4.5
            // "Transactions and locks"), and it makes a check-in wait for a sync of the same user.
            $this->users->lock($userId) ?? throw new AccountNotFound();
            $calendar = $this->calendars->of($userId) ?? throw new PlanRequired();
            $stored = $this->progress->find($userId) ?? throw new LogicException('A plan exists without progress.');
            $today = $calendar->today($now);

            if ($date->isBefore($calendar->plan->startDate)) {
                throw new DayNotOpen(Rejection::BeforeStart);
            }
            if ($calendar->isClosed($date, $stored->nextDay, $now)) {
                throw new DayNotOpen(Rejection::DayFinalized);
            }
            if ($date->isAfter($today)) {
                throw new DayNotOpen(Rejection::InFuture);
            }

            $record = $this->days->find($userId, $date);
            if (!($record?->isCheckedIn() ?? false)) {
                $record = new DayRecord($userId, $date, $record->count ?? 0, $now);
                $this->days->save($record);
            }

            $projection = Projection::of($calendar, $stored, $this->days->between($userId, $stored->nextDay, $today), $today);

            return new CheckInResult($calendar->dayView($date, $record, $stored->nextDay), $projection->eventsOn($date), $projection->view());
        });
    }
}
