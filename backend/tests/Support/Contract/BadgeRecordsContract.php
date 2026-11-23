<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Badge\BadgeRecords;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every implementation of the badge records does (HLD §4.10): the PDO one against the database
 * and the in-memory one the use case tests run on.
 */
trait BadgeRecordsContract
{
    abstract protected function badgeRecords(): BadgeRecords;

    /** Makes sure the accounts exist, where rows cannot exist without one. */
    abstract protected function givenUsers(UserId ...$ids): void;

    public function test_FR_GAME_08_the_badges_of_a_user_are_listed_in_the_order_of_the_badge_cases_with_their_time(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        // Stored in another order than the cases have.
        $champion = TestData::badge(1, Badge::LeagueChampionHalfYear, '2026-07-03 22:00:41');
        $streak = TestData::badge(1, Badge::CheckInStreak7, '2026-11-17 23:00:20');
        $first = TestData::badge(1, Badge::FirstCheckIn, '2026-11-18 23:00:20');
        $other = TestData::badge(2, Badge::FirstCheckIn, '2026-11-16 23:00:00');

        foreach ([$champion, $streak, $first, $other] as $badge) {
            self::assertTrue($this->badgeRecords()->add($badge));
        }

        self::assertEquals([$first, $streak, $champion], $this->badgeRecords()->ofUser(TestData::userId(1)));
        self::assertEquals([$other], $this->badgeRecords()->ofUser(TestData::userId(2)));
        self::assertSame([], $this->badgeRecords()->ofUser(TestData::userId(3)));
    }

    public function test_FR_GAME_08_every_badge_can_be_stored_and_read(): void
    {
        $this->givenUsers(TestData::userId(1));
        $all = array_map(static fn (Badge $badge): object => TestData::badge(1, $badge), Badge::cases());

        foreach ($all as $badge) {
            $this->badgeRecords()->add($badge);
        }

        self::assertEquals($all, $this->badgeRecords()->ofUser(TestData::userId(1)));
    }

    public function test_FR_GAME_08_a_badge_is_awarded_once_and_the_first_award_stays(): void
    {
        $this->givenUsers(TestData::userId(1));
        $first = TestData::badge(1, Badge::FirstSmokeFreeDay, '2026-11-17 23:00:20');
        self::assertTrue($this->badgeRecords()->add($first));

        // The same badge again, later: nothing is stored, and the answer says so.
        self::assertFalse($this->badgeRecords()->add(TestData::badge(1, Badge::FirstSmokeFreeDay, '2026-11-19 23:00:20')));
        self::assertFalse($this->badgeRecords()->add($first));

        self::assertEquals([$first], $this->badgeRecords()->ofUser(TestData::userId(1)));
    }

    public function test_FR_FRND_03_the_badges_of_several_users_come_by_user_without_their_time(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3), TestData::userId(4));
        $this->badgeRecords()->add(TestData::badge(1, Badge::FiveDuelsWon));
        $this->badgeRecords()->add(TestData::badge(1, Badge::FirstCheckIn));
        $this->badgeRecords()->add(TestData::badge(2, Badge::Cheerleader));
        $this->badgeRecords()->add(TestData::badge(4, Badge::FirstCheckIn));

        // User 3 has no badge and is not in the answer. User 4 was not asked for.
        self::assertSame(
            [
                TestData::uuid(1) => [Badge::FirstCheckIn, Badge::FiveDuelsWon],
                TestData::uuid(2) => [Badge::Cheerleader],
            ],
            $this->badgeRecords()->heldBy([TestData::userId(2), TestData::userId(1), TestData::userId(3)]),
        );
        self::assertSame([], $this->badgeRecords()->heldBy([TestData::userId(3)]));
        self::assertSame([], $this->badgeRecords()->heldBy([]));
    }
}
