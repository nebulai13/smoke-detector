<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Day\Calendar;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Domain\Competition\CompetitionScore;
use SmokeDetector\Domain\Competition\CompetitionScoreComparator;
use SmokeDetector\Domain\Xp\LeaguePeriod;

/**
 * GET /leagues (FR-COMP-06, FR-COMP-08, FR-FRND-03; HLD §4.9 "What a user sees"): the user and their
 * friends of today over one calendar period, in ranking order.
 *
 * - Each member's score is the stored result where there is one; otherwise the days that count so
 *   far, as in a running duel (§4.8), from the first day of the period or of the plan.
 * - The user's own percentage is always shown. A friend's only once the user's own rank is stored,
 *   and only from the friend's stored result: a percentage that moves every day could be calculated
 *   back to daily counts. It is not even put into the view otherwise.
 *
 * Locks nothing and writes nothing: one transaction only so that everything is read from one snapshot.
 */
final readonly class ShowLeague
{
    /** The period that holds the user's today. */
    public const string CURRENT = 'current';

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private Calendars $calendars,
        private DayRecords $records,
        private LeagueResults $results,
    ) {
    }

    /**
     * @param string $period A key of the type, or "current".
     *
     * @throws AccountNotFound The account was deleted in the meantime.
     * @throws PlanRequired    The user has not done the onboarding: there is no calendar yet.
     * @throws InvalidValue    The period is no key of the type, or no such period exists.
     * @throws LeagueNotFound  The period has not begun for the user.
     */
    public function show(UserId $userId, LeaguePeriod $type, string $period): LeagueView
    {
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $type, $period, $now): LeagueView {
            $user = $this->users->find($userId) ?? throw new AccountNotFound();
            $calendar = $this->calendars->of($userId) ?? throw new PlanRequired();
            $today = $calendar->today($now);
            $league = $period === self::CURRENT ? CalendarPeriod::containing($type, $today) : CalendarPeriod::fromKey($type, $period);
            if ($league->first()->isAfter($today)) {
                throw new LeagueNotFound();
            }
            $award = $this->results->find($userId, $league)?->award;
            $final = $award !== null;

            $members = [$this->member($user, true, $calendar, $league, $final, $now)];
            foreach (LeagueFriends::of($this->links, $userId) as $friendId) {
                $friend = $this->users->find($friendId);
                if ($friend !== null) {
                    $members[] = $this->member($friend, false, $this->calendars->of($friendId), $league, $final, $now);
                }
            }

            return new LeagueView($league, $final, self::entries($members), $award);
        });
    }

    /**
     * One member with their score, if any, and whether the viewer may see their percentage.
     *
     * @return array{PublicProfile, bool, CompetitionScore|null, bool}
     */
    private function member(User $user, bool $you, ?Calendar $calendar, CalendarPeriod $league, bool $final, DateTimeImmutable $now): array
    {
        $stored = $this->results->find($user->id, $league);
        if ($stored !== null) {
            return [PublicProfile::of($user), $you, $stored->score(), $you || $final];
        }

        return [PublicProfile::of($user), $you, $calendar === null ? null : $this->scoreSoFar($calendar, $league, $now), $you];
    }

    /**
     * The days of the period that count so far, from its first day or from the first day of the plan.
     *
     * @return CompetitionScore|null Null while no day counts.
     */
    private function scoreSoFar(Calendar $calendar, CalendarPeriod $league, DateTimeImmutable $now): ?CompetitionScore
    {
        $startDate = $calendar->plan->startDate;
        $start = $startDate->isAfter($league->first()) ? $startDate : $league->first();

        return DuelSides::read($this->records, $calendar, $start, $league->last(), null, $now)->score();
    }

    /**
     * Ranks the members and puts them in order: by rank, then by name without regard to capitals,
     * then by id; those without a score last, by name.
     *
     * @param list<array{PublicProfile, bool, CompetitionScore|null, bool}> $members
     *
     * @return list<LeagueEntry>
     */
    private static function entries(array $members): array
    {
        $comparator = new CompetitionScoreComparator();
        $entries = [];
        foreach ($members as [$profile, $you, $score, $showPercent]) {
            if ($score === null) {
                $entries[] = new LeagueEntry($profile, $you, null, null, null, null, null);
                continue;
            }
            $better = 0;
            foreach ($members as [, , $other]) {
                $better += $other !== null && $comparator->compare($other, $score) > 0 ? 1 : 0;
            }
            $entries[] = new LeagueEntry(
                $profile,
                $you,
                1 + $better,
                $showPercent ? (int) $score->reduction->toPercent(0) : null,
                $score->smokeFreeDays,
                $score->checkIns,
                $score->countedDays,
            );
        }
        usort(
            $entries,
            static fn (LeagueEntry $a, LeagueEntry $b): int => [$a->rank === null, $a->rank] <=> [$b->rank === null, $b->rank]
                ?: PublicProfile::compareByName($a->user, $b->user),
        );

        return $entries;
    }
}
