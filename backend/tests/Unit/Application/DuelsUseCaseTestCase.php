<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\DecideDuels;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\DuelDecision;
use SmokeDetector\Application\Competition\DuelDetail;
use SmokeDetector\Application\Competition\DuelInvitations;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Competition\DuelView;
use SmokeDetector\Application\Competition\ShowDuels;
use SmokeDetector\Application\Competition\SideStanding;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Support\TestData;

/**
 * The world of the duels (HLD §4.8), without any I/O: what the friends have, plus in-memory duels.
 *
 * It is noon UTC on Tuesday, 17 November 2026. Users live in Vienna, so their today is the 17th.
 * With the rule table v1 a starting baseline of 12 is a frozen baseline of 12.0: a day with 6
 * sessions is a reduction of 50 %. Friends see whole percentages of a duel, and of a single day one
 * of four levels (HLD §4.8): exact figures would give the baseline and the counts away.
 */
abstract class DuelsUseCaseTestCase extends FriendsUseCaseTestCase
{
    protected function duelInvitations(): DuelInvitations
    {
        return new DuelInvitations($this->transactions, $this->clock, $this->users, $this->friendLinks, $this->duels, $this->calendars(), $this->ids);
    }

    protected function showDuels(): ShowDuels
    {
        return new ShowDuels($this->transactions, $this->clock, $this->users, $this->friendLinks, $this->duels, $this->calendars(), $this->dayRecords, $this->progressRecords);
    }

    protected function decideDuels(): DecideDuels
    {
        return new DecideDuels(
            $this->transactions,
            $this->clock,
            $this->users,
            $this->duels,
            $this->calendars(),
            $this->dayRecords,
            $this->progressRecords,
            $this->xpLedger,
            $this->ids,
            $this->logger,
            $this->awardBadges(),
        );
    }

    /**
     * Two users who are friends and have done the onboarding on $startDate, with a baseline of 12.
     * Every day before $nextDay is final for both.
     *
     * @return array{User, User}
     */
    protected function givenTwoFriends(string $startDate = '2026-11-01', string $nextDay = '2026-11-15'): array
    {
        $anna = $this->givenNamed('Anna');
        $bob = $this->givenNamed('Bob');
        $this->givenFriends($anna, $bob);
        $this->givenOnboarded($anna, $startDate, $nextDay);
        $this->givenOnboarded($bob, $startDate, $nextDay);

        return [$anna, $bob];
    }

    /**
     * A proposal of $challenger to $opponent with the id TestData::uuid($number), made at $createdAt.
     */
    protected function givenProposal(int $number, User $challenger, User $opponent, int $periodDays = 7, string $createdAt = '2026-11-17 09:00:00'): Duel
    {
        $duel = new Duel(
            TestData::uuid($number), $challenger->id, $opponent->id, $periodDays, DuelStatus::Proposed,
            TestData::time($createdAt), TestData::time($createdAt)->modify('+72 hours'),
        );
        $this->duels->save($duel);

        return $duel;
    }

    /**
     * A running duel: accepted the day before it started. Without an own start the opponent starts
     * on the same date as the challenger.
     */
    protected function givenRunning(int $number, User $challenger, User $opponent, string $start = '2026-11-13', int $periodDays = 7, ?string $opponentStart = null): Duel
    {
        $accepted = TestData::time($start . ' 08:00:00')->modify('-1 day');
        $duel = new Duel(
            TestData::uuid($number), $challenger->id, $opponent->id, $periodDays, DuelStatus::Active,
            $accepted->modify('-1 hour'), $accepted->modify('+71 hours'), $accepted, self::date($start), self::date($opponentStart ?? $start),
        );
        $this->duels->save($duel);

        return $duel;
    }

    /**
     * A duel that ended in the given way, with the given decision if it has one.
     */
    protected function givenEnded(int $number, User $challenger, User $opponent, DuelStatus $status, ?DuelDecision $decision = null, string $start = '2026-11-03'): Duel
    {
        $running = $this->givenRunning($number, $challenger, $opponent, $start);
        $duel = new Duel(
            $running->id, $running->challengerId, $running->opponentId, $running->periodDays, $status, $running->createdAt, $running->expiresAt,
            $running->answeredAt, $running->challengerStart, $running->opponentStart, $decision,
        );
        $this->duels->save($duel);

        return $duel;
    }

    /**
     * Stores the days of a user from $firstDate on, one per entry: a number is the count of a day
     * that was checked in, a number in a one-item list the count of a day that was not, and null
     * a day without any row.
     *
     * @param list<int|array{int}|null> $days
     */
    protected function givenDays(User $user, string $firstDate, array $days): void
    {
        $date = self::date($firstDate);
        foreach ($days as $day) {
            if (is_int($day)) {
                $this->givenDay($user, $date->value, $day, $date->value . ' 20:00:00');
            } elseif (is_array($day)) {
                $this->givenDay($user, $date->value, $day[0]);
            }
            $date = $date->plusDays(1);
        }
    }

    /**
     * @return array{int|null, int, int, int}|null Whole percent (null for the opponent while the duel runs), smoke-free days, check-ins, counted days.
     */
    protected static function side(?SideStanding $side): ?array
    {
        return $side === null ? null : [$side->reductionPercent, $side->smokeFreeDays, $side->checkIns, $side->countedDays];
    }

    /**
     * @return array<string, mixed> What a user sees of a duel, without its id and the two times.
     */
    protected static function duelView(DuelView $view): array
    {
        return [
            'status' => $view->status->value,
            'days' => $view->periodDays,
            'challenger' => $view->challenger,
            'opponent' => $view->opponent?->displayName->value,
            'from' => $view->startsOn?->value,
            'to' => $view->endsOn?->value,
            'mine' => self::side($view->mine),
            'theirs' => self::side($view->theirs),
            'standing' => $view->standing?->value,
            'outcome' => $view->outcome?->value,
            'xp' => $view->xp,
            'provisional' => $view->provisional,
        ];
    }

    /**
     * @return list<array{int, string|null, string|null}> Day, and the level of the day for the user and for the opponent.
     */
    protected static function dayStandings(DuelDetail $detail): array
    {
        $days = [];
        foreach ($detail->days as $day) {
            $days[] = [$day->day, $day->mine?->value, $day->theirs?->value];
        }

        return $days;
    }

    /**
     * @return list<array{string, string, int, string}> Source, date, amount and time of every XP award of the user.
     */
    protected function awards(User $user): array
    {
        return array_map(
            static fn (XpEvent $event): array => [$event->source->value, $event->date->value, $event->amount, $event->createdAt->format('Y-m-d H:i:s')],
            $this->xpLedger->ofUser($user->id),
        );
    }

    protected static function unknownId(): string
    {
        return SequentialIdGenerator::id(999999);
    }
}
