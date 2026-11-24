<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Nudge;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\FriendNotFound;
use SmokeDetector\Application\Friends\SocialLimits;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /friends/nudge (HLD §4.14, SRS FR-SOC-03, FR-SOC-04): a user reminds a friend to check in,
 * once per friend and day of the sender.
 *
 * A nudge can always be sent. It is delivered only if the friend has a plan and has not checked in
 * on the friend's own today, and the sender is told nothing either way (decided by the owner on
 * 2026-11-23): whether a friend has checked in today is data of a day that is not final. So both
 * cases take the same path: the same reads, one insert, the same answer. Only the stored value
 * `delivered` differs, which the sender never sees.
 */
final readonly class SendNudge
{
    /** Nudges and reactions together, per user and day in UTC (FR-SOC-04): SocialLimits. */
    public const int DAILY_ACTIONS = SocialLimits::DAILY_ACTIONS;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private Nudges $nudges,
        private RateLimiter $limiter,
        private Calendars $calendars,
        private DayRecords $dayRecords,
    ) {
    }

    /**
     * @throws AccountNotFound   The account of $from was deleted in the meantime.
     * @throws RateLimitExceeded $from has had DAILY_ACTIONS today. A refused request counts too.
     * @throws FriendNotFound    $to is not an accepted friend (or is $from), or the account does not
     *                           exist (any more): the same answer.
     * @throws AlreadyNudged     $from has nudged $to on the own today already, delivered or not.
     */
    public function execute(UserId $from, UserId $to): void
    {
        $now = $this->clock->now();

        // As in React: looked up before counting, so that nothing is counted for an account that is
        // gone, and counted outside the transaction, so that a refused nudge stays counted.
        if ($this->users->find($from) === null) {
            throw new AccountNotFound();
        }
        $this->limiter->consume(SocialLimits::actionsPerUser(), $from->value, $now);

        $this->transactions->run(function () use ($from, $to, $now): void {
            // Two nudges of one user, one after the other: the second finds the first.
            $account = $this->users->lock($from);
            if ($account === null) {
                throw new AccountNotFound();
            }
            // The user's own id has no link to itself, and an id that does not exist has none either.
            if (!($this->links->between($from, $to)?->isAccepted() ?? false) || $this->users->find($to) === null) {
                throw new FriendNotFound();
            }
            // The day of the sender follows the zone of the account (HLD §4.14 "Known limits").
            $nudge = new Nudge($from, $to, LocalDate::of($now, $account->timeZone), $now, $this->isDelivered($to, $now));
            // Whether a nudge of that day exists is decided by the insert, after every read: the
            // refusal comes the same way whatever became of the first one.
            if (!$this->nudges->add($nudge)) {
                throw new AlreadyNudged();
            }
        });
    }

    /**
     * Whether the friend has a plan and has not checked in on the friend's own today. With a plan, the
     * day is read whether it has a check-in or not: the same work for both answers.
     */
    private function isDelivered(UserId $to, DateTimeImmutable $now): bool
    {
        $calendar = $this->calendars->of($to);

        return $calendar !== null && !($this->dayRecords->find($to, $calendar->today($now))?->isCheckedIn() ?? false);
    }
}
