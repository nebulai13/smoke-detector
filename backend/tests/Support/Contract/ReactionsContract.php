<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Feed\Reaction;
use SmokeDetector\Application\Feed\ReactionKey;
use SmokeDetector\Application\Feed\Reactions;
use SmokeDetector\Tests\Support\Fake\SequenceFeedIds;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every implementation of the reactions does (HLD §4.13): the PDO one against the database and
 * the in-memory one the use case tests run on.
 */
trait ReactionsContract
{
    abstract protected function reactions(): Reactions;

    /**
     * Makes sure the accounts and the feed entries exist, where rows cannot exist without them: the
     * users with these numbers, and the entries SequenceFeedIds::id(1) to id($events) about user 1.
     */
    abstract protected function givenUsersAndEvents(int $users, int $events): void;

    public function test_FR_SOC_02_a_reaction_is_found_by_its_entry_and_its_user_with_every_value(): void
    {
        $this->givenUsersAndEvents(3, 2);
        $cheer = self::reaction(1, 2, ReactionKey::Cheer, '2026-11-17 12:00:05');
        $strong = self::reaction(1, 3, ReactionKey::Strong, '2026-11-17 12:00:06');
        $keepGoing = self::reaction(2, 2, ReactionKey::KeepGoing, '2026-11-18 08:00:00');

        foreach ([$cheer, $strong, $keepGoing] as $reaction) {
            self::assertTrue($this->reactions()->save($reaction));
        }

        self::assertEquals($cheer, $this->reactions()->find(SequenceFeedIds::id(1), TestData::userId(2)));
        self::assertEquals($strong, $this->reactions()->find(SequenceFeedIds::id(1), TestData::userId(3)));
        self::assertEquals($keepGoing, $this->reactions()->find(SequenceFeedIds::id(2), TestData::userId(2)));
        self::assertNull($this->reactions()->find(SequenceFeedIds::id(2), TestData::userId(3)));
        self::assertNull($this->reactions()->find(SequenceFeedIds::id(9), TestData::userId(2)));
    }

    public function test_FR_SOC_02_a_user_has_one_reaction_to_an_entry_which_can_change_and_keeps_its_time(): void
    {
        $this->givenUsersAndEvents(2, 1);
        self::assertTrue($this->reactions()->save(self::reaction(1, 2, ReactionKey::Cheer, '2026-11-17 12:00:05')));

        // The same user reacts to the same entry again: nothing new, and the answer says so.
        self::assertFalse($this->reactions()->save(self::reaction(1, 2, ReactionKey::Strong, '2026-11-19 09:00:00')));

        self::assertEquals(self::reaction(1, 2, ReactionKey::Strong, '2026-11-17 12:00:05'), $this->reactions()->find(SequenceFeedIds::id(1), TestData::userId(2)));
        self::assertSame(1, $this->reactions()->countSentBy(TestData::userId(2)));
    }

    public function test_FR_SOC_02_the_reactions_to_some_entries_come_by_entry_oldest_first(): void
    {
        $this->givenUsersAndEvents(4, 3);
        $late = self::reaction(1, 3, ReactionKey::Strong, '2026-11-17 12:00:09');
        $early = self::reaction(1, 2, ReactionKey::Cheer, '2026-11-17 12:00:05');
        $other = self::reaction(2, 4, ReactionKey::KeepGoing, '2026-11-17 12:00:01');
        $notAsked = self::reaction(3, 2, ReactionKey::Cheer, '2026-11-17 12:00:02');
        foreach ([$late, $early, $other, $notAsked] as $reaction) {
            $this->reactions()->save($reaction);
        }

        // An entry without a reaction is not in the answer. Entry 3 was not asked for.
        self::assertEquals(
            [SequenceFeedIds::id(1) => [$early, $late], SequenceFeedIds::id(2) => [$other]],
            $this->reactions()->ofEvents([SequenceFeedIds::id(2), SequenceFeedIds::id(1), SequenceFeedIds::id(9)]),
        );
        self::assertSame([], $this->reactions()->ofEvents([SequenceFeedIds::id(9)]));
        self::assertSame([], $this->reactions()->ofEvents([]));
    }

    public function test_FR_GAME_08_the_reactions_a_user_sent_are_counted_and_listed_oldest_first(): void
    {
        $this->givenUsersAndEvents(3, 3);
        $second = self::reaction(2, 2, ReactionKey::Strong, '2026-11-18 08:00:00');
        $first = self::reaction(1, 2, ReactionKey::Cheer, '2026-11-17 12:00:05');
        $third = self::reaction(3, 2, ReactionKey::KeepGoing, '2026-11-19 08:00:00');
        $others = self::reaction(1, 3, ReactionKey::Cheer, '2026-11-17 12:00:06');
        foreach ([$second, $first, $third, $others] as $reaction) {
            $this->reactions()->save($reaction);
        }

        self::assertSame(3, $this->reactions()->countSentBy(TestData::userId(2)));
        self::assertSame(1, $this->reactions()->countSentBy(TestData::userId(3)));
        self::assertSame(0, $this->reactions()->countSentBy(TestData::userId(1)));
        self::assertEquals([$first, $second, $third], $this->reactions()->sentBy(TestData::userId(2)));
        self::assertSame([], $this->reactions()->sentBy(TestData::userId(1)));
    }

    private static function reaction(int $event, int $user, ReactionKey $key, string $createdAt): Reaction
    {
        return new Reaction(SequenceFeedIds::id($event), TestData::userId($user), $key, TestData::time($createdAt));
    }
}
