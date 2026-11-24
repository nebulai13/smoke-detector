<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Nudge;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Application\TransactionRunner;

/**
 * GET /nudges (HLD §4.14 "What the receiver sees", SRS FR-SOC-03): the friends whose nudges of the
 * user's own today were delivered, the first one first, as name and avatar. No time.
 *
 * Empty before the onboarding and once the user has checked in today: the nudges did their work. A
 * nudge of somebody who is no friend now, or whose account is gone, is left out.
 *
 * Locks nothing: one transaction only so that everything is read from one snapshot.
 */
final readonly class ShowNudges
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private Nudges $nudges,
        private Calendars $calendars,
        private DayRecords $dayRecords,
    ) {
    }

    /**
     * @return list<PublicProfile> Each sender once, in the order of the first nudge.
     */
    public function of(UserId $userId): array
    {
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $now): array {
            $calendar = $this->calendars->of($userId);
            if ($calendar === null) {
                return [];
            }
            $today = $calendar->today($now);
            if ($this->dayRecords->find($userId, $today)?->isCheckedIn() ?? false) {
                return [];
            }

            $senders = [];
            foreach ($this->nudges->deliveredTo($userId, $calendar->days->startOf($today)) as $nudge) {
                if (!($this->links->between($userId, $nudge->from)?->isAccepted() ?? false)) {
                    continue;
                }
                $sender = $this->users->find($nudge->from);
                if ($sender !== null) {
                    // Two days of a sender can fall into one day of the user. A key that is there
                    // already keeps its place: the first nudge decides the order.
                    $senders[$sender->id->value] = PublicProfile::of($sender);
                }
            }

            return array_values($senders);
        });
    }
}
