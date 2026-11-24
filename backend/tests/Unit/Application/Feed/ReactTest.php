<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Feed;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\CompetitionRewards;
use SmokeDetector\Application\Feed\FeedEntryNotFound;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Feed\FeedEventType;
use SmokeDetector\Application\Feed\OwnFeedEntry;
use SmokeDetector\Application\Feed\React;
use SmokeDetector\Application\Feed\Reaction;
use SmokeDetector\Application\Feed\ReactionKey;
use SmokeDetector\Application\Feed\ReactionRewards;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Tests\Support\Fake\SequenceFeedIds;
use SmokeDetector\Tests\Support\RateLimitKey;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LeaguesUseCaseTestCase;

/**
 * `POST /feed/reactions` (HLD §4.13; FR-SOC-02, FR-SOC-04, FR-GAME-01, FR-GAME-08): a user reacts to
 * an entry of a friend with one of three preset reactions. One reaction per entry, which can change.
 * A new reaction earns 2 XP, at most 10 a day. Reactions and nudges together are limited to 30 a day.
 *
 * It is noon UTC on Tuesday, 17 November 2026. Anna and Bob are friends, live in Vienna and have a
 * plan. Bob has the entries SequenceFeedIds::id(1) to id(40): levels 1 to 40.
 */
final class ReactTest extends LeaguesUseCaseTestCase
{
    private User $anna;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->anna, $this->bob] = $this->givenTwoFriends();
        for ($level = 1; $level <= 40; $level++) {
            $this->feedEvents->append(new FeedEvent($this->feedIds->next(), $this->bob->id, FeedEventType::LevelUp, (string) $level, null, $this->now()));
        }
    }

    public function test_FR_SOC_02_a_reaction_to_an_entry_of_a_friend_is_stored_and_earns_2_xp_today(): void
    {
        $before = $this->progressRecords->find($this->anna->id) ?? self::fail('No progress.');

        $result = $this->react()->execute($this->anna->id, SequenceFeedIds::id(7), ReactionKey::Cheer);

        self::assertSame([ReactionKey::Cheer, 2], [$result->reaction, $result->xp]);
        self::assertEquals([new Reaction(SequenceFeedIds::id(7), $this->anna->id, ReactionKey::Cheer, $this->now())], $this->reactions->all());
        // In the ledger on Anna's own today, and in her progress at once: it is no smoking data.
        self::assertSame([['reaction', '2026-11-17', 2, '2026-11-17 12:00:00']], $this->awards($this->anna));
        $after = $this->progressRecords->find($this->anna->id) ?? self::fail('No progress.');
        self::assertSame($before->progress->totalXp + 2, $after->progress->totalXp);
        self::assertEquals([$before->nextDay, $before->finalizeDueAt], [$after->nextDay, $after->finalizeDueAt]);
        // Bob gets nothing for being cheered.
        self::assertSame([], $this->awards($this->bob));
    }

    public function test_FR_GAME_01_reactions_earn_at_most_10_xp_a_day_and_are_stored_all_the_same(): void
    {
        $xp = [];
        for ($entry = 1; $entry <= 7; $entry++) {
            $xp[] = $this->react()->execute($this->anna->id, SequenceFeedIds::id($entry), ReactionKey::Strong)->xp;
        }

        self::assertSame([2, 2, 2, 2, 2, 0, 0], $xp);
        self::assertCount(7, $this->reactions->all());
        self::assertCount(5, $this->awards($this->anna));

        // Anna's next day begins at 23:00 UTC.
        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));
        self::assertSame(2, $this->react()->execute($this->anna->id, SequenceFeedIds::id(8), ReactionKey::Strong)->xp);
        self::assertSame(['reaction', '2026-11-18', 2, '2026-11-17 23:00:00'], $this->awards($this->anna)[5]);
    }

    public function test_FR_SOC_02_a_user_has_one_reaction_to_an_entry_and_changing_it_earns_nothing_again(): void
    {
        $this->react()->execute($this->anna->id, SequenceFeedIds::id(7), ReactionKey::Cheer);
        $this->clock->advanceSeconds(60);

        $changed = $this->react()->execute($this->anna->id, SequenceFeedIds::id(7), ReactionKey::KeepGoing);

        self::assertSame([ReactionKey::KeepGoing, 0], [$changed->reaction, $changed->xp]);
        // One reaction, with its first time.
        self::assertEquals([new Reaction(SequenceFeedIds::id(7), $this->anna->id, ReactionKey::KeepGoing, TestData::time('2026-11-17 12:00:00'))], $this->reactions->all());
        self::assertCount(1, $this->awards($this->anna));
    }

    public function test_FR_SOC_02_only_an_entry_the_user_sees_in_the_feed_can_be_reacted_to(): void
    {
        $stranger = $this->givenPlayer('Stranger');
        $asker = $this->givenPlayer('Asker');
        $this->givenRequest($asker, $this->anna);
        $carol = $this->givenPlayer('Carol');
        $this->givenFriends($this->anna, $carol);
        $this->userSettings->save($carol->id, new Settings(MilestoneSharing::Private));
        $hidden = [
            'of a stranger' => $this->givenEntry($stranger, FeedEventType::LevelUp, '1'),
            'of somebody with an open request' => $this->givenEntry($asker, FeedEventType::LevelUp, '1'),
            'a health milestone its owner keeps private' => $this->givenEntry($carol, FeedEventType::HealthMilestone, 'circulation'),
            'with a value the code does not know' => $this->givenEntry($this->bob, FeedEventType::BadgeEarned, 'moon_landing'),
            'that does not exist' => SequenceFeedIds::id(9999),
        ];

        foreach ($hidden as $case => $entryId) {
            try {
                $this->react()->execute($this->anna->id, $entryId, ReactionKey::Cheer);
                self::fail("An entry {$case} took a reaction.");
            } catch (FeedEntryNotFound) {
                // The same answer for all of them: it does not tell whether an entry exists.
            }
        }
        self::assertSame([], $this->reactions->all());
        self::assertSame([], $this->awards($this->anna));
        // Carol's level is no secret.
        self::assertSame(2, $this->react()->execute($this->anna->id, $this->givenEntry($carol, FeedEventType::LevelUp, '3'), ReactionKey::Cheer)->xp);
    }

    public function test_FR_SOC_02_an_entry_of_a_friend_who_was_removed_or_blocked_takes_no_reaction_any_more(): void
    {
        $carol = $this->givenPlayer('Carol');
        $this->givenFriends($this->anna, $carol);
        $carols = $this->givenEntry($carol, FeedEventType::LevelUp, '3');
        $this->react()->execute($this->anna->id, $carols, ReactionKey::Cheer);

        // Anna removes Carol, and blocks Bob (which ends the friendship).
        $this->friendList()->remove($this->anna->id, $carol->id);
        $this->blocking()->block($this->anna->id, $this->bob->id);

        foreach ([$carols, SequenceFeedIds::id(7)] as $entryId) {
            try {
                $this->react()->execute($this->anna->id, $entryId, ReactionKey::Strong);
                self::fail('An entry of somebody who is no friend any more took a reaction.');
            } catch (FeedEntryNotFound) {
                // The reaction that was sent before stays as it was.
            }
        }
        self::assertEquals([new Reaction($carols, $this->anna->id, ReactionKey::Cheer, $this->now())], $this->reactions->all());
    }

    public function test_FR_SOC_02_a_health_milestone_its_owner_shows_can_be_reacted_to(): void
    {
        $circulation = $this->givenEntry($this->bob, FeedEventType::HealthMilestone, 'circulation');
        $short = $this->givenEntry($this->bob, FeedEventType::HealthMilestone, 'carbon_monoxide');

        // Bob never chose: the milestones from two weeks on.
        self::assertSame(ReactionKey::Cheer, $this->react()->execute($this->anna->id, $circulation, ReactionKey::Cheer)->reaction);
        try {
            $this->react()->execute($this->anna->id, $short, ReactionKey::Cheer);
            self::fail('A milestone that is not shown took a reaction.');
        } catch (FeedEntryNotFound) {
            self::assertCount(1, $this->reactions->all());
        }
    }

    public function test_FR_SOC_04_a_request_that_is_refused_is_one_of_the_thirty(): void
    {
        // Otherwise the ids of entries could be tried without end.
        for ($try = 1; $try <= 30; $try++) {
            try {
                $this->react()->execute($this->anna->id, SequenceFeedIds::id(9000 + $try), ReactionKey::Cheer);
                self::fail('An entry that does not exist took a reaction.');
            } catch (FeedEntryNotFound) {
                // Counted.
            }
        }

        $this->expectException(RateLimitExceeded::class);

        $this->react()->execute($this->anna->id, SequenceFeedIds::id(7), ReactionKey::Cheer);
    }

    public function test_FR_COMP_07_the_xp_of_a_reaction_counts_in_the_weekly_xp_ranking_at_once(): void
    {
        $this->react()->execute($this->anna->id, SequenceFeedIds::id(7), ReactionKey::Cheer);

        // Unlike the XP of a day, which waits until the day is final: a reaction is no smoking data.
        // So friends can see that somebody reacted to something just now, and not to what.
        $xp = [];
        foreach ($this->showXpLeague()->show($this->bob->id)->entries as $entry) {
            $xp[$entry->user->displayName->value] = $entry->xp;
        }
        ksort($xp);
        self::assertSame(['Anna' => 2, 'Bob' => 0], $xp);
    }

    public function test_FR_SOC_02_nobody_reacts_to_an_own_entry(): void
    {
        $own = $this->givenEntry($this->anna, FeedEventType::LevelUp, '1');

        try {
            $this->react()->execute($this->anna->id, $own, ReactionKey::Cheer);
            self::fail('An own entry took a reaction.');
        } catch (OwnFeedEntry) {
            self::assertSame([], $this->reactions->all());
        }
    }

    public function test_FR_SOC_04_thirty_reactions_a_day_and_the_next_one_is_refused(): void
    {
        for ($entry = 1; $entry <= 29; $entry++) {
            $this->react()->execute($this->anna->id, SequenceFeedIds::id($entry), ReactionKey::Cheer);
        }
        // Changing a reaction is one of the thirty too.
        $this->react()->execute($this->anna->id, SequenceFeedIds::id(1), ReactionKey::Strong);

        try {
            $this->react()->execute($this->anna->id, SequenceFeedIds::id(30), ReactionKey::Cheer);
            self::fail('The thirty-first action of the day was taken.');
        } catch (RateLimitExceeded $refused) {
            // Until midnight UTC, twelve hours from now.
            self::assertSame(43_200, $refused->retryAfterSeconds);
        }
        self::assertCount(29, $this->reactions->all());
        self::assertSame(30, React::DAILY_ACTIONS);
        // Bob has his own thirty.
        $this->givenEntry($this->anna, FeedEventType::LevelUp, '1');
        self::assertSame(2, $this->react()->execute($this->bob->id, SequenceFeedIds::id(41), ReactionKey::Cheer)->xp);

        $this->clock->travelTo(TestData::time('2026-11-18 00:00:00'));
        self::assertSame(ReactionKey::Cheer, $this->react()->execute($this->anna->id, SequenceFeedIds::id(30), ReactionKey::Cheer)->reaction);
    }

    public function test_FR_SOC_02_somebody_without_a_plan_can_react_and_earns_nothing(): void
    {
        $new = $this->givenNamed('New');
        $this->givenFriends($new, $this->bob);

        $result = $this->react()->execute($new->id, SequenceFeedIds::id(7), ReactionKey::Cheer);

        // There is no day to book the XP on, and no progress to add it to.
        self::assertSame([ReactionKey::Cheer, 0], [$result->reaction, $result->xp]);
        self::assertCount(1, $this->reactions->all());
        self::assertSame([], $this->awards($new));
    }

    public function test_FR_GAME_05_the_xp_of_a_reaction_can_reach_a_level_which_is_an_entry_of_the_feed(): void
    {
        $this->givenProgress($this->anna, '2026-11-15', null, 99, 0);

        $this->react()->execute($this->anna->id, SequenceFeedIds::id(7), ReactionKey::Cheer);

        // 101 XP: level 1 begins at 100.
        self::assertSame([101, 1], [$this->progressRecords->find($this->anna->id)?->progress->totalXp, $this->progressRecords->find($this->anna->id)?->level]);
        self::assertSame([['level_up', '1', null]], $this->feedOf($this->anna));
    }

    public function test_FR_GAME_08_the_fiftieth_reaction_sent_earns_the_cheerleader_badge(): void
    {
        // 49 sent before: 48 to entries long gone from the feed, and one to Bob's level 7.
        for ($sent = 1; $sent <= 48; $sent++) {
            $this->reactions->save(new Reaction(SequenceFeedIds::id(1000 + $sent), $this->anna->id, ReactionKey::Cheer, TestData::time('2026-11-01 12:00:00')));
        }
        // Changing a reaction is no reaction more.
        $this->reactions->save(new Reaction(SequenceFeedIds::id(7), $this->anna->id, ReactionKey::Cheer, TestData::time('2026-11-01 12:00:00')));
        $this->reactions->save(new Reaction(SequenceFeedIds::id(1001), $this->anna->id, ReactionKey::Strong, TestData::time('2026-11-02 12:00:00')));
        $this->react()->execute($this->anna->id, SequenceFeedIds::id(7), ReactionKey::Strong);
        self::assertSame([], $this->badgeRecords->all());

        $this->react()->execute($this->anna->id, SequenceFeedIds::id(8), ReactionKey::Cheer);

        self::assertSame([['cheerleader', '2026-11-17 12:00:00']], $this->badgesOf($this->anna));
        self::assertSame([['badge_earned', 'cheerleader', null]], $this->feedOf($this->anna));
    }

    public function test_FR_ACC_08_an_account_that_was_deleted_in_the_meantime_reacts_to_nothing(): void
    {
        $this->users->delete($this->anna->id);

        try {
            $this->react()->execute($this->anna->id, SequenceFeedIds::id(7), ReactionKey::Cheer);
            self::fail('An account that is gone reacted.');
        } catch (AccountNotFound) {
            // Nothing is counted for an account that is gone: no row is left behind for nobody.
            self::assertSame([], $this->counters->all());
            self::assertSame([], $this->reactions->all());
        }
    }

    private function react(): React
    {
        return new React(
            $this->transactions,
            $this->clock,
            $this->users,
            $this->friendLinks,
            $this->feedEvents,
            $this->userSettings,
            $this->reactions,
            new RateLimiter($this->counters, RateLimitKey::hasher()),
            new ReactionRewards(
                $this->calendars(),
                $this->xpLedger,
                new CompetitionRewards($this->progressRecords, $this->xpLedger, $this->ids, $this->publishToFeed()),
                $this->awardBadges(),
                $this->reactions,
            ),
        );
    }

    /**
     * @return string The id of a new entry about the user.
     */
    private function givenEntry(User $user, FeedEventType $type, string $detail): string
    {
        $id = $this->feedIds->next();
        $this->feedEvents->append(new FeedEvent($id, $user->id, $type, $detail, null, $this->now()));

        return $id;
    }
}
