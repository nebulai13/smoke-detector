<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Feed;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Feed\FeedEntry;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Feed\FeedEventType;
use SmokeDetector\Application\Feed\Reaction;
use SmokeDetector\Application\Feed\ReactionKey;
use SmokeDetector\Application\Feed\ReactionView;
use SmokeDetector\Application\Feed\ShowFeed;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Tests\Support\Fake\SequenceFeedIds;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\FriendsUseCaseTestCase;

/**
 * `GET /feed` (HLD §4.12 "What a user sees"; FR-SOC-01, FR-FRND-03): the newest entries about the
 * user and the user's friends. An entry has no time. A health milestone of a friend is shown as far as
 * that friend's setting allows, and the opponent of a duel only to somebody who is a friend of the
 * opponent too.
 */
final class ShowFeedTest extends FriendsUseCaseTestCase
{
    private User $anna;
    private User $bob;
    private int $events = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenNamed('Anna');
        $this->bob = $this->givenNamed('Bob');
        $this->givenFriends($this->anna, $this->bob);
    }

    public function test_FR_SOC_01_the_feed_is_what_the_user_and_the_friends_reached_newest_first(): void
    {
        $this->givenEvent($this->bob, FeedEventType::BadgeEarned, 'first_smoke_free_day');
        $this->givenEvent($this->anna, FeedEventType::LevelUp, '3');
        $this->givenEvent($this->bob, FeedEventType::HealthMilestone, 'circulation');
        $this->givenEvent($this->bob, FeedEventType::LevelUp, '12');

        $entries = $this->showFeed()->show($this->anna->id);

        self::assertSame(
            [
                [SequenceFeedIds::id(4), 'level_up', 'Bob', false, 12, null, null, null],
                [SequenceFeedIds::id(3), 'health_milestone', 'Bob', false, null, null, 'circulation', null],
                [SequenceFeedIds::id(2), 'level_up', 'Anna', true, 3, null, null, null],
                [SequenceFeedIds::id(1), 'badge_earned', 'Bob', false, null, 'first_smoke_free_day', null, null],
            ],
            array_map(self::entry(...), $entries),
        );
        self::assertSame([$this->bob->id->value, 'Bob', 'avatar-07'], self::profile($entries[0]->user));
    }

    public function test_FR_FRND_03_an_entry_has_no_time(): void
    {
        $this->givenEvent($this->bob, FeedEventType::BadgeEarned, 'first_smoke_free_day');

        $entry = $this->showFeed()->show($this->anna->id)[0];

        // When a badge was earned is nobody's business but the owner's (HLD §4.10). The order is all there is.
        self::assertSame(['id', 'type', 'user', 'mine', 'level', 'badge', 'milestone', 'opponent', 'myReaction', 'reactions'], array_keys(get_object_vars($entry)));
    }

    public function test_FR_SOC_02_an_entry_shows_the_users_own_reaction_and_the_owner_sees_who_reacted(): void
    {
        $carol = $this->givenNamed('Carol');
        $dave = $this->givenNamed('Dave');
        $this->givenFriends($this->bob, $carol);
        $this->givenFriends($this->bob, $dave);
        $this->givenFriends($this->anna, $carol);
        $this->givenEvent($this->bob, FeedEventType::LevelUp, '2');
        $this->givenEvent($this->bob, FeedEventType::LevelUp, '3');
        // To Bob's level 2: Carol first, then Anna, then Dave, whom Bob removes afterwards.
        $this->reactions->save(new Reaction(SequenceFeedIds::id(1), $carol->id, ReactionKey::Strong, TestData::time('2026-11-17 12:00:01')));
        $this->reactions->save(new Reaction(SequenceFeedIds::id(1), $this->anna->id, ReactionKey::Cheer, TestData::time('2026-11-17 12:00:02')));
        $this->reactions->save(new Reaction(SequenceFeedIds::id(1), $dave->id, ReactionKey::KeepGoing, TestData::time('2026-11-17 12:00:03')));
        $this->friendLinks->delete($this->bob->id, $dave->id);

        $reactions = static fn (FeedEntry $entry): array => array_map(
            static fn (ReactionView $reaction): array => [$reaction->user->displayName->value, $reaction->reaction->value],
            $entry->reactions,
        );

        // Bob, whose entries they are: who reacted, oldest first, of those who are his friends now.
        $bobs = $this->showFeed()->show($this->bob->id);
        self::assertSame([[3, null, []], [2, null, [['Carol', 'strong'], ['Anna', 'cheer']]]], array_map(static fn (FeedEntry $entry): array => [$entry->level, $entry->myReaction?->value, $reactions($entry)], $bobs));
        // Anna sees her own reaction, and not who else reacted to Bob's entry.
        $annas = $this->showFeed()->show($this->anna->id);
        self::assertSame([[3, null, []], [2, 'cheer', []]], array_map(static fn (FeedEntry $entry): array => [$entry->level, $entry->myReaction?->value, $reactions($entry)], $annas));
    }

    public function test_FR_SOC_01_only_friends_are_in_the_feed(): void
    {
        $stranger = $this->givenNamed('Stranger');
        $asker = $this->givenNamed('Asker');
        $asked = $this->givenNamed('Asked');
        $blocked = $this->givenNamed('Blocked');
        $this->givenRequest($asker, $this->anna);
        $this->givenRequest($this->anna, $asked);
        $this->givenBlock($this->anna, $blocked);
        foreach ([$stranger, $asker, $asked, $blocked, $this->bob] as $user) {
            $this->givenEvent($user, FeedEventType::LevelUp, '1');
        }

        // An open request in either direction is no friendship, and a block ended one.
        self::assertSame(['Bob'], array_column(array_map(self::entry(...), $this->showFeed()->show($this->anna->id)), 2));
        // Somebody without friends sees the own entries.
        self::assertSame(['Stranger'], array_column(array_map(self::entry(...), $this->showFeed()->show($stranger->id)), 2));
    }

    public function test_FR_ACC_08_an_entry_whose_account_is_gone_is_left_out(): void
    {
        $carol = $this->givenNamed('Carol');
        $this->givenFriends($this->anna, $carol);
        $this->givenEvent($carol, FeedEventType::LevelUp, '1');
        $this->givenEvent($this->bob, FeedEventType::LevelUp, '2');
        // The account is gone and its rows are still there for a moment (the eraser is not through).
        $this->users->delete($carol->id);

        self::assertSame(['Bob'], array_column(array_map(self::entry(...), $this->showFeed()->show($this->anna->id)), 2));
    }

    public function test_FR_FRND_03_a_health_milestone_of_a_friend_is_shown_as_far_as_the_friends_setting_allows(): void
    {
        $carol = $this->givenNamed('Carol');
        $dave = $this->givenNamed('Dave');
        $this->givenFriends($this->anna, $carol);
        $this->givenFriends($this->anna, $dave);
        $this->userSettings->save($carol->id, new Settings(MilestoneSharing::All));
        $this->userSettings->save($dave->id, new Settings(MilestoneSharing::Private));
        // Anna keeps hers private: she sees her own all the same.
        $this->userSettings->save($this->anna->id, new Settings(MilestoneSharing::Private));
        foreach ([$this->bob, $carol, $dave, $this->anna] as $user) {
            $this->givenEvent($user, FeedEventType::HealthMilestone, 'carbon_monoxide');
            $this->givenEvent($user, FeedEventType::HealthMilestone, 'circulation');
        }

        $shown = [];
        foreach ($this->showFeed()->show($this->anna->id) as $entry) {
            $shown[$entry->user->displayName->value][] = $entry->milestone?->value;
        }

        // Bob never chose: from two weeks on. Carol shows all, Dave none.
        self::assertSame(
            ['Anna' => ['circulation', 'carbon_monoxide'], 'Carol' => ['circulation', 'carbon_monoxide'], 'Bob' => ['circulation']],
            $shown,
        );
    }

    public function test_FR_SOC_01_the_opponent_of_a_duel_is_named_only_to_a_friend_of_the_opponent(): void
    {
        $carol = $this->givenNamed('Carol');
        $dave = $this->givenNamed('Dave');
        // Anna is a friend of Bob and Carol. Bob is a friend of Carol and Dave. Anna does not know Dave.
        $this->givenFriends($this->anna, $carol);
        $this->givenFriends($this->bob, $carol);
        $this->givenFriends($this->bob, $dave);
        $this->givenEvent($this->bob, FeedEventType::DuelWon, null, $carol);
        $this->givenEvent($this->bob, FeedEventType::DuelWon, null, $dave);
        $this->givenEvent($this->bob, FeedEventType::DuelDraw, null, $this->anna);
        $this->givenEvent($this->anna, FeedEventType::DuelDraw, null, $this->bob);
        $this->givenEvent($this->bob, FeedEventType::DuelWon, null, null);

        // Newest first. An opponent whose account is gone has no name either.
        self::assertSame(
            [['Bob', 'duel_won', null], ['Anna', 'duel_draw', 'Bob'], ['Bob', 'duel_draw', 'Anna'], ['Bob', 'duel_won', null], ['Bob', 'duel_won', 'Carol']],
            self::opponents($this->showFeed()->show($this->anna->id)),
        );
        // Dave sees Bob's entries only, and of the opponents only himself.
        self::assertSame(
            [['Bob', 'duel_won', null], ['Bob', 'duel_draw', null], ['Bob', 'duel_won', 'Dave'], ['Bob', 'duel_won', null]],
            self::opponents($this->showFeed()->show($dave->id)),
        );
    }

    public function test_FR_SOC_01_the_feed_has_the_fifty_newest_entries_the_user_may_see(): void
    {
        $this->userSettings->save($this->bob->id, new Settings(MilestoneSharing::Private));
        for ($level = 1; $level <= 55; $level++) {
            $this->givenEvent($this->bob, FeedEventType::LevelUp, (string) $level);
        }
        // Newer than all of them, and not for Anna to see: they take nobody's place.
        for ($hidden = 0; $hidden < 40; $hidden++) {
            $this->givenEvent($this->bob, FeedEventType::HealthMilestone, 'circulation');
        }

        $entries = $this->showFeed()->show($this->anna->id);

        self::assertSame(50, ShowFeed::LIMIT);
        self::assertSame(range(55, 6), array_map(static fn (FeedEntry $entry): ?int => $entry->level, $entries));
    }

    public function test_FR_SOC_01_entries_the_user_may_not_see_never_shorten_the_feed(): void
    {
        // An audit found this: a group of friends who all begin in the same week have hundreds of
        // health milestones among them that the others may not see, all newer than what they may.
        $this->userSettings->save($this->bob->id, new Settings(MilestoneSharing::Private));
        for ($level = 1; $level <= 60; $level++) {
            $this->givenEvent($this->bob, FeedEventType::LevelUp, (string) $level);
        }
        for ($hidden = 0; $hidden < 450; $hidden++) {
            $this->givenEvent($this->bob, FeedEventType::HealthMilestone, 'circulation');
        }

        self::assertSame(
            range(60, 11),
            array_map(static fn (FeedEntry $entry): ?int => $entry->level, $this->showFeed()->show($this->anna->id)),
        );
        // And a feed that has fewer than fifty is complete, however much is hidden in between.
        $carol = $this->givenNamed('Carol');
        $this->givenFriends($carol, $this->bob);
        $this->givenEvent($carol, FeedEventType::LevelUp, '1');
        for ($hidden = 0; $hidden < 250; $hidden++) {
            $this->givenEvent($this->bob, FeedEventType::HealthMilestone, 'circulation');
        }
        $this->givenEvent($carol, FeedEventType::LevelUp, '2');
        $dave = $this->givenNamed('Dave');
        $this->givenFriends($dave, $carol);

        self::assertSame([2, 1], array_map(static fn (FeedEntry $entry): ?int => $entry->level, $this->showFeed()->show($dave->id)));
    }

    public function test_FR_SOC_01_only_a_duel_has_an_opponent(): void
    {
        $carol = $this->givenNamed('Carol');
        $this->givenFriends($this->anna, $carol);
        // Damaged rows: entries that are no duels and name another user all the same.
        $this->givenEvent($this->bob, FeedEventType::LevelUp, '2', $carol);
        $this->givenEvent($this->bob, FeedEventType::BadgeEarned, 'first_check_in', $this->anna);
        $this->givenEvent($this->bob, FeedEventType::HealthMilestone, 'circulation', $carol);
        $this->givenEvent($this->bob, FeedEventType::DuelDraw, null, $carol);

        // Carol is Anna's friend, and Anna is the viewer: a duel would name them.
        self::assertSame(
            [['Bob', 'duel_draw', 'Carol'], ['Bob', 'health_milestone', null], ['Bob', 'badge_earned', null], ['Bob', 'level_up', null]],
            self::opponents($this->showFeed()->show($this->anna->id)),
        );
    }

    public function test_an_entry_with_a_value_the_code_does_not_know_is_left_out(): void
    {
        // Written by a later version of the app, before a roll-back.
        $this->givenEvent($this->bob, FeedEventType::BadgeEarned, 'moon_landing');
        $this->givenEvent($this->bob, FeedEventType::HealthMilestone, 'immortality');
        $this->givenEvent($this->bob, FeedEventType::LevelUp, 'high');
        $this->givenEvent($this->bob, FeedEventType::LevelUp, '0');
        // Damaged: an entry that needs a detail and has none.
        $this->givenEvent($this->bob, FeedEventType::BadgeEarned);
        $this->givenEvent($this->bob, FeedEventType::HealthMilestone);
        $this->givenEvent($this->bob, FeedEventType::LevelUp);
        $this->givenEvent($this->bob, FeedEventType::BadgeEarned, Badge::Cheerleader->value);
        $this->givenEvent($this->bob, FeedEventType::HealthMilestone, HealthMilestone::Stroke->value);

        self::assertSame(
            [[null, null, 'stroke'], [null, 'cheerleader', null]],
            array_map(static fn (FeedEntry $entry): array => [$entry->level, $entry->badge?->value, $entry->milestone?->value], $this->showFeed()->show($this->anna->id)),
        );
    }

    /**
     * @param list<FeedEntry> $entries
     *
     * @return list<array{string, string, string|null}> Name, type and the name of the opponent of every entry.
     */
    private static function opponents(array $entries): array
    {
        return array_map(
            static fn (FeedEntry $entry): array => [$entry->user->displayName->value, $entry->type->value, $entry->opponent?->displayName->value],
            $entries,
        );
    }

    private function showFeed(): ShowFeed
    {
        return new ShowFeed($this->transactions, $this->users, $this->friendLinks, $this->feedEvents, $this->userSettings, $this->reactions);
    }

    private function givenEvent(User $user, FeedEventType $type, ?string $detail = null, ?User $other = null): void
    {
        $this->feedEvents->append(new FeedEvent(SequenceFeedIds::id(++$this->events), $user->id, $type, $detail, $other?->id, TestData::time('2026-11-17 12:00:00')));
    }

    /**
     * @return array{string, string, string, bool, int|null, string|null, string|null, string|null} Id, type, name,
     *                                                                                                whether it is the
     *                                                                                                user's own, level,
     *                                                                                                badge, milestone
     *                                                                                                and the name of the
     *                                                                                                opponent.
     */
    private static function entry(FeedEntry $entry): array
    {
        return [
            $entry->id, $entry->type->value, $entry->user->displayName->value, $entry->mine, $entry->level,
            $entry->badge?->value, $entry->milestone?->value, $entry->opponent?->displayName->value,
        ];
    }
}
