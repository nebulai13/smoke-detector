<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\XpLedger;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Domain\Xp\LeaguePeriod;

/**
 * GET /leagues/xp (FR-COMP-07, HLD §4.9 "Weekly XP ranking"): the user and their friends by the XP
 * written to the ledger in the user's current ISO week, from the start of the user's Monday to the
 * start of the Monday after it, as two instants that are the same for everybody in the list.
 *
 * Locks nothing and writes nothing.
 */
final readonly class ShowXpLeague
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private Calendars $calendars,
        private XpLedger $ledger,
    ) {
    }

    /**
     * @throws AccountNotFound The account was deleted in the meantime.
     * @throws PlanRequired    The user has not done the onboarding: there is no calendar yet.
     */
    public function show(UserId $userId): XpLeagueView
    {
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $now): XpLeagueView {
            $user = $this->users->find($userId) ?? throw new AccountNotFound();
            $calendar = $this->calendars->of($userId) ?? throw new PlanRequired();
            $week = CalendarPeriod::containing(LeaguePeriod::Week, $calendar->today($now));
            $from = $calendar->days->startOf($week->first());
            $until = $calendar->days->startOf($week->last()->plusDays(1));

            /** @var list<array{PublicProfile, bool, int}> $members */
            $members = [[PublicProfile::of($user), true, $this->ledger->earnedBetween($userId, $from, $until)]];
            foreach (LeagueFriends::of($this->links, $userId) as $friendId) {
                $friend = $this->users->find($friendId);
                if ($friend !== null) {
                    $members[] = [PublicProfile::of($friend), false, $this->ledger->earnedBetween($friendId, $from, $until)];
                }
            }

            $entries = [];
            foreach ($members as [$profile, $you, $xp]) {
                $more = count(array_filter($members, static fn (array $other): bool => $other[2] > $xp));
                $entries[] = new XpLeagueEntry($profile, $you, 1 + $more, $xp);
            }
            usort(
                $entries,
                static fn (XpLeagueEntry $a, XpLeagueEntry $b): int => $a->rank <=> $b->rank ?: PublicProfile::compareByName($a->user, $b->user),
            );

            return new XpLeagueView($week, $entries);
        });
    }
}
