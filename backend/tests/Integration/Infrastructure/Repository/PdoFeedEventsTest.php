<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Feed\FeedEvents;
use SmokeDetector\Application\Feed\FeedEventType;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoFeedEvents;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\Contract\FeedEventsContract;
use SmokeDetector\Tests\Support\Fake\SequenceFeedIds;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestData;

/**
 * The feed entries against the real database: the contract every implementation passes, and what
 * only the database can show (the columns, and what happens to an entry when an account goes).
 */
final class PdoFeedEventsTest extends IntegrationTestCase
{
    use FeedEventsContract;

    private Database $database;

    protected function setUp(): void
    {
        // A connection of its own, as the application has: the test's own connection looks at the
        // rows from outside.
        $this->database = $this->newDatabase();
    }

    public function test_FR_SOC_01_an_entry_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));

        $this->feedEvents()->append(new FeedEvent(SequenceFeedIds::id(1), TestData::userId(1), FeedEventType::DuelWon, null, TestData::userId(2), TestData::time('2026-11-18 23:01:05')));
        $this->feedEvents()->append(new FeedEvent(SequenceFeedIds::id(2), TestData::userId(1), FeedEventType::BadgeEarned, 'league_champion_half_year', null, TestData::time('2026-11-18 23:01:05')));

        $rows = $this->sql()->rows('SELECT id, user_id, type, detail, other_user_id, created_at FROM feed_events ORDER BY position');
        self::assertSame(
            [
                ['id' => SequenceFeedIds::id(1), 'user_id' => TestData::uuid(1), 'type' => 'duel_won', 'detail' => null, 'other_user_id' => TestData::uuid(2), 'created_at' => '2026-11-18 23:01:05'],
                ['id' => SequenceFeedIds::id(2), 'user_id' => TestData::uuid(1), 'type' => 'badge_earned', 'detail' => 'league_champion_half_year', 'other_user_id' => null, 'created_at' => '2026-11-18 23:01:05'],
            ],
            $rows,
        );
    }

    public function test_FR_ACC_08_an_entry_goes_with_its_user_and_only_forgets_an_opponent_who_goes(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->feedEvents()->append(new FeedEvent(SequenceFeedIds::id(1), TestData::userId(1), FeedEventType::DuelWon, null, TestData::userId(2), TestData::time('2026-11-18 23:01:05')));
        $this->feedEvents()->append(new FeedEvent(SequenceFeedIds::id(2), TestData::userId(2), FeedEventType::LevelUp, '1', null, TestData::time('2026-11-18 23:01:05')));

        $this->sql()->execute('DELETE FROM users WHERE id = ?', [TestData::uuid(2)]);

        self::assertSame(
            [['id' => SequenceFeedIds::id(1), 'user_id' => TestData::uuid(1), 'other_user_id' => null]],
            $this->sql()->rows('SELECT id, user_id, other_user_id FROM feed_events'),
        );
    }

    protected function feedEvents(): FeedEvents
    {
        return new PdoFeedEvents(new Sql($this->database));
    }

    protected function givenUsers(UserId ...$ids): void
    {
        $users = new PdoUserRepository(new Sql($this->database));
        foreach ($ids as $id) {
            if ($users->find($id) === null) {
                $users->add(TestData::user((int) substr($id->value, -12)));
            }
        }
    }
}
