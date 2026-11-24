<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application;

use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Badge\AwardBadges;
use SmokeDetector\Application\Badge\AwardedBadge;
use SmokeDetector\Application\Competition\LeagueScores;
use SmokeDetector\Application\Day\CheckIn;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Application\Day\DayResult;
use SmokeDetector\Application\Day\DayView;
use SmokeDetector\Application\Day\FinalizeDays;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Day\ProgressView;
use SmokeDetector\Application\Day\ShowDashboard;
use SmokeDetector\Application\Day\ShowDays;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Feed\PublishToFeed;
use SmokeDetector\Application\Logging\SmokeLog;
use SmokeDetector\Application\Logging\SyncLogs;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Recovery\AwardMilestones;
use SmokeDetector\Application\Recovery\ReachedMilestone;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Time\LocalDays;
use SmokeDetector\Application\Time\ZoneChange;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Streak\StreakState;
use SmokeDetector\Tests\Support\InMemory\InMemoryBadgeRecords;
use SmokeDetector\Tests\Support\InMemory\InMemoryDayRecords;
use SmokeDetector\Tests\Support\Fake\SequenceFeedIds;
use SmokeDetector\Tests\Support\InMemory\InMemoryDuels;
use SmokeDetector\Tests\Support\InMemory\InMemoryFeedEvents;
use SmokeDetector\Tests\Support\InMemory\InMemoryLeagueResults;
use SmokeDetector\Tests\Support\InMemory\InMemoryMilestoneRecords;
use SmokeDetector\Tests\Support\InMemory\InMemoryNudges;
use SmokeDetector\Tests\Support\InMemory\InMemoryReactions;
use SmokeDetector\Tests\Support\InMemory\InMemorySmokeLogs;
use SmokeDetector\Tests\Support\InMemory\InMemoryUserSettings;
use SmokeDetector\Tests\Support\InMemory\InMemoryXpLedger;
use SmokeDetector\Tests\Support\TestData;

/**
 * The world of logging, check-in and day finalization (HLD §4.6), without any I/O: what the account
 * use cases have, plus in-memory logs, days and XP ledger.
 *
 * It is noon UTC on Tuesday, 17 November 2026. Users live in Vienna, one hour ahead: their 17th runs
 * from 23:00 UTC on the 16th to 23:00 UTC on the 17th. With the rule table v1 a baseline of 12 and
 * the normal taper speed give a limit of 9.
 */
abstract class LoggingUseCaseTestCase extends AccountUseCaseTestCase
{
    protected InMemorySmokeLogs $smokeLogs;
    protected InMemoryDayRecords $dayRecords;
    protected InMemoryXpLedger $xpLedger;
    protected InMemoryLeagueResults $leagueResults;
    protected InMemoryBadgeRecords $badgeRecords;
    protected InMemoryMilestoneRecords $milestoneRecords;
    protected InMemoryUserSettings $userSettings;
    protected InMemoryFeedEvents $feedEvents;
    protected SequenceFeedIds $feedIds;
    protected InMemoryReactions $reactions;
    protected InMemoryNudges $nudges;
    /** Here and not with the duel tests: the badges of the duels are awarded from what is stored of them. */
    protected InMemoryDuels $duels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smokeLogs = new InMemorySmokeLogs($this->transactions);
        $this->dayRecords = new InMemoryDayRecords($this->transactions);
        $this->xpLedger = new InMemoryXpLedger($this->transactions);
        $this->leagueResults = new InMemoryLeagueResults($this->transactions);
        $this->badgeRecords = new InMemoryBadgeRecords($this->transactions);
        $this->milestoneRecords = new InMemoryMilestoneRecords($this->transactions);
        $this->userSettings = new InMemoryUserSettings($this->transactions);
        $this->feedEvents = new InMemoryFeedEvents($this->transactions);
        $this->feedIds = new SequenceFeedIds();
        $this->reactions = new InMemoryReactions($this->transactions);
        $this->nudges = new InMemoryNudges($this->transactions);
        $this->duels = new InMemoryDuels($this->progressRecords, $this->transactions);
    }

    protected function syncLogs(): SyncLogs
    {
        return new SyncLogs($this->transactions, $this->clock, $this->users, $this->calendars(), $this->smokeLogs, $this->dayRecords, $this->progressRecords);
    }

    protected function checkIn(): CheckIn
    {
        return new CheckIn($this->transactions, $this->clock, $this->users, $this->calendars(), $this->dayRecords, $this->progressRecords);
    }

    protected function showDays(): ShowDays
    {
        return new ShowDays($this->clock, $this->calendars(), $this->dayRecords, $this->progressRecords);
    }

    protected function showDashboard(): ShowDashboard
    {
        return new ShowDashboard($this->clock, $this->calendars(), $this->dayRecords, $this->progressRecords);
    }

    protected function finalizeDays(): FinalizeDays
    {
        return new FinalizeDays(
            $this->transactions,
            $this->clock,
            $this->users,
            $this->calendars(),
            $this->smokeLogs,
            $this->dayRecords,
            $this->progressRecords,
            $this->xpLedger,
            $this->baselines,
            $this->ids,
            $this->logger,
            new LeagueScores($this->leagueResults, $this->dayRecords),
            $this->awardBadges(),
            new AwardMilestones($this->milestoneRecords, $this->smokeLogs, $this->dayRecords, $this->publishToFeed()),
            $this->publishToFeed(),
        );
    }

    /**
     * @return list<array{string, string}> Key and time of every health milestone that is stored for the user, in the order of the milestones.
     */
    protected function milestonesOf(User $user): array
    {
        return array_map(
            static fn (ReachedMilestone $reached): array => [$reached->milestone->value, $reached->reachedAt->format('Y-m-d H:i:s')],
            $this->milestoneRecords->ofUser($user->id),
        );
    }

    /**
     * What stores the badges a final day, a decided duel or a league rank justifies (HLD §4.10).
     */
    protected function awardBadges(): AwardBadges
    {
        return new AwardBadges($this->badgeRecords, $this->duels, $this->publishToFeed());
    }

    /**
     * What writes the entries of the activity feed, from final data only (HLD §4.12).
     */
    protected function publishToFeed(): PublishToFeed
    {
        return new PublishToFeed($this->feedEvents, $this->feedIds);
    }

    /**
     * @return list<array{string, string|null, string|null}> Type, detail and the id of the other user of every feed entry about the user, oldest first.
     */
    protected function feedOf(User $user): array
    {
        return array_map(
            static fn (FeedEvent $event): array => [$event->type->value, $event->detail, $event->otherUserId?->value],
            $this->feedEvents->ofUser($user->id),
        );
    }

    /**
     * @return list<array{string, string}> Key and time of every badge the user holds, in the order of the badge cases.
     */
    protected function badgesOf(User $user): array
    {
        return array_map(
            static fn (AwardedBadge $badge): array => [$badge->badge->value, $badge->awardedAt->format('Y-m-d H:i:s')],
            $this->badgeRecords->ofUser($user->id),
        );
    }

    /**
     * @return list<string> The keys of the badges the user holds, in the order of the badge cases.
     */
    protected function badgeKeysOf(User $user): array
    {
        return array_column($this->badgesOf($user), 0);
    }

    /**
     * A user who did the onboarding on $startDate: plan, starting baseline, the first entries of
     * both histories, and progress whose next day to finalize is $nextDay (the start date, unless
     * said otherwise: every day before $nextDay counts as final).
     *
     * @param array{int, int, int, int, int}|null $streaks Check-in current and longest, under-limit
     *                                                     current and longest, freezes held.
     */
    protected function givenOnboarded(
        User $user,
        string $startDate = '2026-11-10',
        ?string $nextDay = null,
        int $startingBaseline = 12,
        ?Goal $goal = null,
        TaperSpeed $taperSpeed = TaperSpeed::Normal,
        ?array $streaks = null,
        int $totalXp = 0,
        int $level = 0,
    ): void {
        $this->givenPlan($user, $startingBaseline, $goal, $taperSpeed, ProductType::Cigarette, $startDate);
        $this->givenProgress($user, $nextDay ?? $startDate, $streaks, $totalXp, $level);
    }

    /**
     * Stores the progress of a user as finalization would have left it, due when the zone history
     * says: 48 hours after the end of $nextDay, and the weekly baseline at the start of $baselineDay.
     *
     * @param array{int, int, int, int, int}|null $streaks
     */
    protected function givenProgress(
        User $user,
        string $nextDay,
        ?array $streaks = null,
        int $totalXp = 0,
        int $level = 0,
        string $baselineDay = '2026-11-23',
    ): ProgressRecord {
        $days = new LocalDays($this->zoneHistory->ofUser($user->id));
        $record = new ProgressRecord(
            $user->id,
            new UserProgress($streaks === null ? StreakState::initial() : new StreakState(...$streaks), $totalXp),
            $level,
            self::date($nextDay),
            $days->endOf(self::date($nextDay))->modify('+48 hours'),
            $days->startOf(self::date($baselineDay)),
        );
        $this->progressRecords->save($record);

        return $record;
    }

    /**
     * A day as it is stored while it is open: its count, and its check-in if there was one.
     */
    protected function givenDay(User $user, string $date, int $count = 0, ?string $checkedInAt = null, ?DayResult $result = null): DayRecord
    {
        $day = new DayRecord($user->id, self::date($date), $count, $checkedInAt === null ? null : TestData::time($checkedInAt), $result);
        $this->dayRecords->save($day);

        return $day;
    }

    /**
     * A stored log with the id TestData::uuid($number). Does not touch the count of its day.
     */
    protected function givenLog(
        User $user,
        int $number,
        string $localDate,
        string $smokedAt,
        int $count = 1,
        ProductType $productType = ProductType::Cigarette,
        ?string $deletedAt = null,
        string $storedAt = '2026-11-15 09:00:00',
    ): SmokeLog {
        $log = new SmokeLog(
            TestData::uuid($number),
            $user->id,
            TestData::time($smokedAt),
            self::date($localDate),
            $productType,
            $count,
            TestData::time($storedAt),
            TestData::time($deletedAt ?? $storedAt),
            $deletedAt === null ? null : TestData::time($deletedAt),
        );
        $this->smokeLogs->save($log);

        return $log;
    }

    /**
     * Gives the user another zone from $validFrom on, as a change of the profile would.
     */
    protected function givenZoneChange(User $user, string $validFrom, string $zone): User
    {
        $this->zoneHistory->record($user->id, new ZoneChange(self::date($validFrom), TimeZone::fromInput($zone)));
        $moved = $user->withProfile($user->displayName, $user->avatar, $user->locale, TimeZone::fromInput($zone));
        $this->users->update($moved);

        return $moved;
    }

    protected static function date(string $value): LocalDate
    {
        return LocalDate::fromString($value);
    }

    /**
     * @return array{string, int, int, int, bool, bool} Date, count, limit, remaining, checked in, final.
     */
    protected static function view(DayView $day): array
    {
        return [$day->date->value, $day->count, $day->limit, $day->remaining(), $day->checkedIn, $day->final];
    }

    /**
     * @param list<DayView> $days
     *
     * @return list<array{string, int, int, int, bool, bool}>
     */
    protected static function views(array $days): array
    {
        return array_map(self::view(...), $days);
    }

    /**
     * @param list<DayEvent> $events
     *
     * @return list<string> "type" or "type:value", in order.
     */
    protected static function events(array $events): array
    {
        return array_map(
            static fn (DayEvent $event): string => $event->value === null ? $event->type->value : "{$event->type->value}:{$event->value}",
            $events,
        );
    }

    /**
     * @return array{array{int, int, int, int, int}, int, array{int, int, int}, bool} Streaks (check-in
     *         current and longest, under-limit current and longest, freezes), XP total, level (number,
     *         XP into it, XP for the next), provisional.
     */
    protected static function progress(ProgressView $view): array
    {
        return [
            [$view->streaks->checkInCurrent, $view->streaks->checkInLongest, $view->streaks->underLimitCurrent, $view->streaks->underLimitLongest, $view->streaks->freezesHeld],
            $view->totalXp,
            [$view->level->level, $view->level->xpIntoLevel, $view->level->xpForNextLevel],
            $view->provisional,
        ];
    }
}
