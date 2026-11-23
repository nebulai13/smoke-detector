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
use SmokeDetector\Application\Day\ProgressRecords;
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
 * friends of today over one calendar period.
 *
 * - Each member's score is the stored result where there is one. Otherwise the user's own entry is
 *   live, the days that count so far as in a running duel (§4.8), and of a friend only the final days
 *   count; both from the first day of the period or of the plan. Who is ahead, and the user's own
 *   rank, are decided over the final days of both (HLD §4.8 "Of the other side, final days only"):
 *   the user can still change the own open days, and could find a friend's reduction by trying.
 * - A final league (the user's own result has its award) ranks stored results only.
 * - While the user's own rank is not stored, friends are not ranked against each other: each is
 *   ahead of the user, level or behind. Otherwise accounts of one's own as friends would show where
 *   a real friend stands among them every day, and so the friend's reduction.
 * - The user's own percentage is always shown. A friend's only once the user's own rank is stored,
 *   only from the friend's stored result and only over MIN_DAYS_FOR_A_PERCENTAGE counted days or
 *   more: a percentage that moves every day, or one over a few days, could be calculated back to
 *   daily counts. It is not even put into the view otherwise.
 *
 * Locks nothing and writes nothing: one transaction only so that everything is read from one snapshot.
 */
final readonly class ShowLeague
{
    /** The period that holds the user's today. */
    public const string CURRENT = 'current';

    /** A friend's final percentage is shown only for a result over this many counted days or more. */
    public const int MIN_DAYS_FOR_A_PERCENTAGE = 7;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private Calendars $calendars,
        private DayRecords $records,
        private ProgressRecords $progress,
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

            return new LeagueView($league, $final, self::entries($members, $final), $award);
        });
    }

    /**
     * One member: the score shown, the score compared with (the same but for the user's own live
     * entry, which is compared over the own final days), and whether the viewer may see the percentage.
     *
     * @return array{PublicProfile, bool, CompetitionScore|null, CompetitionScore|null, bool}
     */
    private function member(User $user, bool $you, ?Calendar $calendar, CalendarPeriod $league, bool $final, DateTimeImmutable $now): array
    {
        $profile = PublicProfile::of($user);
        $stored = $this->results->find($user->id, $league);
        if ($stored !== null) {
            $score = $stored->score();

            return [$profile, $you, $score, $score, $you || ($final && $stored->countedDays >= self::MIN_DAYS_FOR_A_PERCENTAGE)];
        }
        // A final league is made of stored results; the user's own is one (it holds the award).
        if ($final || $calendar === null) {
            return [$profile, $you, null, null, $you];
        }
        $start = $calendar->plan->startDate->isAfter($league->first()) ? $calendar->plan->startDate : $league->first();
        $compared = DuelSides::readFinal($this->records, $this->progress, $calendar, $start, $league->last())->score();
        // Of a friend nothing but the final days is read.
        $shown = $you ? DuelSides::read($this->records, $calendar, $start, $league->last(), null, $now)->score() : $compared;

        return [$profile, $you, $shown, $compared, $you];
    }

    /**
     * The entries in their order, each group by name without regard to capitals, then by id; those
     * without a score last, by name.
     *
     * - Final: everybody with a score ranked, the same rank for equal scores, in ranking order.
     * - Not final: only the user ranked, by the friends who are ahead. Each friend ahead, level or
     *   behind the user, in that order around the user, and never ordered by score among each other.
     *   Without a final score of the user's own nobody is compared and the user has no rank: the
     *   friends with a score come first, then everybody else, the user too.
     *
     * @param list<array{PublicProfile, bool, CompetitionScore|null, CompetitionScore|null, bool}> $members The user first.
     *
     * @return list<LeagueEntry>
     */
    private static function entries(array $members, bool $final): array
    {
        $comparator = new CompetitionScoreComparator();
        $own = $members[0][3];
        // Not final: the friends who are ahead (or all with a score, while the user has none), the
        // user, those who are level, those who are behind. Final: everybody with a score in $ranked.
        [$ranked, $first, $you, $level, $behind, $unscored] = [[], [], [], [], [], []];
        foreach ($members as [$profile, $isYou, $score, $compared, $showPercent]) {
            if ($score === null) {
                $unscored[] = new LeagueEntry($profile, $isYou, null, null, null, null, null, null);
                continue;
            }
            $rank = $compared !== null && ($final || $isYou) ? 1 + self::better($members, $compared, $comparator) : null;
            $standing = $final || $isYou || $own === null || $compared === null ? null : DuelStanding::of($comparator->compare($compared, $own));
            $entry = new LeagueEntry(
                $profile,
                $isYou,
                $rank,
                $standing,
                $showPercent ? (int) $score->reduction->toPercent(0) : null,
                $score->smokeFreeDays,
                $score->checkIns,
                $score->countedDays,
            );
            if ($final) {
                $ranked[] = $entry;
            } elseif ($rank !== null) {
                $you[] = $entry;
            } elseif ($isYou) {
                $unscored[] = $entry;
            } elseif ($standing === DuelStanding::Level) {
                $level[] = $entry;
            } elseif ($standing === DuelStanding::Behind) {
                $behind[] = $entry;
            } else {
                $first[] = $entry;
            }
        }
        usort($ranked, static fn (LeagueEntry $a, LeagueEntry $b): int => $a->rank <=> $b->rank ?: PublicProfile::compareByName($a->user, $b->user));

        return [...$ranked, ...self::byName($first), ...$you, ...self::byName($level), ...self::byName($behind), ...self::byName($unscored)];
    }

    /**
     * @param list<LeagueEntry> $entries
     *
     * @return list<LeagueEntry>
     */
    private static function byName(array $entries): array
    {
        usort($entries, static fn (LeagueEntry $a, LeagueEntry $b): int => PublicProfile::compareByName($a->user, $b->user));

        return $entries;
    }

    /**
     * How many members have a better score, each by the score compared with.
     *
     * @param list<array{PublicProfile, bool, CompetitionScore|null, CompetitionScore|null, bool}> $members
     */
    private static function better(array $members, CompetitionScore $score, CompetitionScoreComparator $comparator): int
    {
        $better = 0;
        foreach ($members as [, , , $other]) {
            $better += $other !== null && $comparator->compare($other, $score) > 0 ? 1 : 0;
        }

        return $better;
    }
}
