<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Repository;

use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Feed\FeedEventType;
use SmokeDetector\Application\Feed\Reaction;
use SmokeDetector\Application\Feed\ReactionKey;
use SmokeDetector\Application\Feed\Reactions;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoFeedEvents;
use SmokeDetector\Infrastructure\Repository\PdoReactions;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\Contract\ReactionsContract;
use SmokeDetector\Tests\Support\Fake\SequenceFeedIds;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestData;

/**
 * The reactions against the real database: the contract every implementation passes, and what only
 * the database can show (the columns, and that a reaction goes with its entry and with its user).
 */
final class PdoReactionsTest extends IntegrationTestCase
{
    use ReactionsContract;

    private Database $database;

    protected function setUp(): void
    {
        // A connection of its own, as the application has: the test's own connection looks at the
        // rows from outside.
        $this->database = $this->newDatabase();
    }

    public function test_FR_SOC_02_a_reaction_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsersAndEvents(2, 1);

        $this->reactions()->save(new Reaction(SequenceFeedIds::id(1), TestData::userId(2), ReactionKey::KeepGoing, TestData::time('2026-11-18 23:01:05')));

        self::assertSame(
            [['event_id' => SequenceFeedIds::id(1), 'user_id' => TestData::uuid(2), 'reaction' => 'keep_going', 'created_at' => '2026-11-18 23:01:05']],
            $this->sql()->rows('SELECT * FROM feed_reactions'),
        );
    }

    public function test_FR_ACC_08_a_reaction_goes_with_its_entry_and_with_its_user(): void
    {
        $this->givenUsersAndEvents(3, 2);
        $this->reactions()->save(new Reaction(SequenceFeedIds::id(1), TestData::userId(2), ReactionKey::Cheer, TestData::time('2026-11-18 23:01:05')));
        $this->reactions()->save(new Reaction(SequenceFeedIds::id(1), TestData::userId(3), ReactionKey::Cheer, TestData::time('2026-11-18 23:01:05')));
        $this->reactions()->save(new Reaction(SequenceFeedIds::id(2), TestData::userId(3), ReactionKey::Cheer, TestData::time('2026-11-18 23:01:05')));

        $this->sql()->execute('DELETE FROM users WHERE id = ?', [TestData::uuid(2)]);
        self::assertSame(2, $this->sql()->value('SELECT COUNT(*) FROM feed_reactions'));

        $this->sql()->execute('DELETE FROM feed_events WHERE id = ?', [SequenceFeedIds::id(1)]);
        self::assertSame(
            [['event_id' => SequenceFeedIds::id(2), 'user_id' => TestData::uuid(3)]],
            $this->sql()->rows('SELECT event_id, user_id FROM feed_reactions'),
        );
    }

    protected function reactions(): Reactions
    {
        return new PdoReactions(new Sql($this->database));
    }

    protected function givenUsersAndEvents(int $users, int $events): void
    {
        $accounts = new PdoUserRepository(new Sql($this->database));
        for ($number = 1; $number <= $users; $number++) {
            if ($accounts->find(TestData::userId($number)) === null) {
                $accounts->add(TestData::user($number));
            }
        }
        $feed = new PdoFeedEvents(new Sql($this->database));
        for ($number = 1; $number <= $events; $number++) {
            $feed->append(new FeedEvent(SequenceFeedIds::id($number), TestData::userId(1), FeedEventType::LevelUp, (string) $number, null, TestData::time('2026-11-17 12:00:00')));
        }
    }
}
