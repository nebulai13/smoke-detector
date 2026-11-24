<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Feed\FeedEvents;
use SmokeDetector\Application\Feed\FeedEventType;
use SmokeDetector\Tests\Support\Fake\SequenceFeedIds;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every implementation of the feed entries does (HLD §4.12): the PDO one against the database
 * and the in-memory one the use case tests run on.
 */
trait FeedEventsContract
{
    abstract protected function feedEvents(): FeedEvents;

    /** Makes sure the accounts exist, where rows cannot exist without one. */
    abstract protected function givenUsers(UserId ...$ids): void;

    public function test_FR_SOC_01_an_entry_is_found_by_its_id_with_every_value(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $badge = self::event(1, 1, FeedEventType::BadgeEarned, 'first_smoke_free_day', null, '2026-11-17 23:00:20');
        $duel = self::event(2, 2, FeedEventType::DuelWon, null, 1, '2026-11-18 23:01:05');
        $level = self::event(3, 1, FeedEventType::LevelUp, '12');
        $milestone = self::event(4, 1, FeedEventType::HealthMilestone, 'heart_disease_halved');
        $draw = self::event(5, 1, FeedEventType::DuelDraw, null, 2);

        foreach ([$badge, $duel, $level, $milestone, $draw] as $event) {
            $this->feedEvents()->append($event);
        }

        foreach ([$badge, $duel, $level, $milestone, $draw] as $event) {
            self::assertEquals($event, $this->feedEvents()->find($event->id));
        }
        self::assertNull($this->feedEvents()->find(SequenceFeedIds::id(99)));
    }

    public function test_FR_SOC_01_the_newest_entries_of_some_users_come_newest_first_up_to_the_limit(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        // Written in this order. The times say something else: several steps of one cron run can
        // have the same time, and a clock can be put back.
        $first = self::event(1, 1, FeedEventType::LevelUp, '1', null, '2026-11-17 23:00:20');
        $second = self::event(2, 2, FeedEventType::LevelUp, '2', null, '2026-11-17 23:00:20');
        $third = self::event(3, 3, FeedEventType::LevelUp, '3', null, '2026-11-16 23:00:00');
        $fourth = self::event(4, 1, FeedEventType::LevelUp, '4', null, '2026-11-17 23:00:20');
        foreach ([$first, $second, $third, $fourth] as $event) {
            $this->feedEvents()->append($event);
        }

        [$one, $two, $three] = [TestData::userId(1), TestData::userId(2), TestData::userId(3)];

        self::assertEquals([$fourth, $third, $second, $first], $this->feedEvents()->newestOf([$one, $two, $three], 10));
        self::assertEquals([$fourth, $second, $first], $this->feedEvents()->newestOf([$two, $one], 10));
        self::assertEquals([$fourth, $third], $this->feedEvents()->newestOf([$one, $two, $three], 2));
        self::assertEquals([$third], $this->feedEvents()->newestOf([$three], 10));
        self::assertSame([], $this->feedEvents()->newestOf([TestData::userId(4)], 10));
        self::assertSame([], $this->feedEvents()->newestOf([], 10));
        // The entries after the newest ones, to read on where a first read ended.
        self::assertEquals([$second, $first], $this->feedEvents()->newestOf([$one, $two, $three], 2, 2));
        self::assertEquals([$third, $second, $first], $this->feedEvents()->newestOf([$one, $two, $three], 10, 1));
        self::assertEquals([$first], $this->feedEvents()->newestOf([$two, $one], 5, 2));
        self::assertSame([], $this->feedEvents()->newestOf([$one, $two, $three], 10, 4));
        self::assertSame([], $this->feedEvents()->newestOf([], 10, 1));
        // A negative number to skip is a mistake of the caller, and never an answer.
        $refused = false;
        try {
            $this->feedEvents()->newestOf([$one], 10, -1);
        } catch (\Throwable) {
            $refused = true;
        }
        self::assertTrue($refused);
    }

    public function test_FR_ACC_07_the_entries_of_one_user_come_oldest_first(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $first = self::event(1, 1, FeedEventType::BadgeEarned, 'first_check_in');
        $other = self::event(2, 2, FeedEventType::DuelWon, null, 1);
        $second = self::event(3, 1, FeedEventType::LevelUp, '1');
        foreach ([$first, $other, $second] as $event) {
            $this->feedEvents()->append($event);
        }

        // Only the entries about the user: being the opponent in somebody else's entry is not one.
        self::assertEquals([$first, $second], $this->feedEvents()->ofUser(TestData::userId(1)));
        self::assertEquals([$other], $this->feedEvents()->ofUser(TestData::userId(2)));
        self::assertSame([], $this->feedEvents()->ofUser(TestData::userId(3)));
    }

    private static function event(int $number, int $user, FeedEventType $type, ?string $detail = null, ?int $other = null, string $createdAt = '2026-11-17 12:00:00'): FeedEvent
    {
        return new FeedEvent(SequenceFeedIds::id($number), TestData::userId($user), $type, $detail, $other === null ? null : TestData::userId($other), TestData::time($createdAt));
    }
}
