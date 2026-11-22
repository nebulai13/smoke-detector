<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use DateTimeImmutable;
use LogicException;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Day\Schedule;
use SmokeDetector\Application\Plan\PlanRepository;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Time\LocalDays;
use SmokeDetector\Application\Time\ZoneChange;
use SmokeDetector\Application\Time\ZoneHistory;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\ValidationFailed;

/**
 * PATCH /me (HLD §4.5 "Account", FR-ACC-09): display name, avatar, language and time zone.
 *
 * The email address cannot be changed here: a new one would have to be confirmed first.
 *
 * After the onboarding a new time zone counts from the user's next day (HLD §4.6 "Local days"):
 * today keeps its zone, so nobody moves today's logs or the end of today after the fact. The account
 * keeps what the user chose last; the zone history says which zone counts on which day.
 */
final readonly class UpdateProfile
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private PlanRepository $plans,
        private ZoneHistory $zones,
        private ProgressRecords $progress,
    ) {
    }

    /**
     * @throws ValidationFailed When nothing is to be changed: most likely a mistake of the client.
     * @throws AccountNotFound  When the account was deleted in the meantime.
     */
    public function execute(UserId $userId, ProfileChange $change): void
    {
        if ($change->isEmpty()) {
            throw ValidationFailed::forField('', ErrorCode::Required, 'At least one of displayName, avatar, locale and timeZone is required.');
        }
        $now = $this->clock->now();

        $this->transactions->run(function () use ($userId, $change, $now): void {
            // Under a lock: update() writes every column, and must not write back a password hash or
            // a confirmation that another request changed since the account was read.
            $user = $this->users->lock($userId) ?? throw new AccountNotFound();

            // Before the onboarding no day has been played: the new zone simply counts.
            if ($change->timeZone !== null && $change->timeZone->name !== $user->timeZone->name && $this->plans->find($user->id) !== null) {
                $this->moveZone($user->id, $change->timeZone, $now);
            }

            $this->users->update($change->applyTo($user));
        });
    }

    /**
     * Records the zone from the user's next day on, and moves the instants at which the cron job
     * is due: the next day to finalize may end at another instant now, and the user's Monday of the
     * next weekly baseline begins at another one.
     *
     * The new zone counts from the first date after today that has not begun on its clock, so no
     * day begins in the past (after a move east past midnight that is the day after tomorrow). It
     * replaces the changes that were waiting from that date on; a change that gives that date the
     * zone it has anyway is no entry.
     */
    private function moveZone(UserId $userId, TimeZone $zone, DateTimeImmutable $now): void
    {
        $before = new LocalDays($this->zones->ofUser($userId));
        $today = $before->dateOf($now);
        $begunThere = LocalDate::of($now, $zone);
        $validFrom = ($begunThere->isAfter($today) ? $begunThere : $today)->plusDays(1);

        $this->zones->dropAfter($userId, $validFrom->plusDays(-1));
        $left = new LocalDays($this->zones->ofUser($userId));
        if ($left->zoneOn($validFrom)->name !== $zone->name) {
            $this->zones->record($userId, new ZoneChange($validFrom, $zone));
        }
        $after = new LocalDays($this->zones->ofUser($userId));

        $stored = $this->progress->find($userId) ?? throw new LogicException('A plan exists without progress.');
        $this->progress->save(new ProgressRecord(
            $userId,
            $stored->progress,
            $stored->level,
            $stored->nextDay,
            Schedule::finalizeDueAt($after, $stored->nextDay),
            // The same local Monday, begun in the zone that counts on it now.
            $after->startOf($before->dateOf($stored->baselineDueAt)),
        ));
    }
}
