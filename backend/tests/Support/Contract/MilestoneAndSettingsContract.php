<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Recovery\MilestoneRecords;
use SmokeDetector\Application\Recovery\ReachedMilestone;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every implementation of the milestone records and of the user settings does (HLD §4.11): the
 * PDO ones against the database and the in-memory ones the use case tests run on.
 */
trait MilestoneAndSettingsContract
{
    abstract protected function milestoneRecords(): MilestoneRecords;

    abstract protected function userSettings(): UserSettings;

    /** Makes sure the accounts exist, where rows cannot exist without one. */
    abstract protected function givenUsers(UserId ...$ids): void;

    public function test_FR_GAME_09_the_milestones_of_a_user_are_listed_in_their_order_with_their_time(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        // Stored in another order than the milestones have.
        $late = self::reached(1, HealthMilestone::Circulation, '2026-11-15 08:00:00');
        $early = self::reached(1, HealthMilestone::HeartRate, '2026-11-01 08:20:00');
        $other = self::reached(2, HealthMilestone::HeartRate, '2026-11-02 09:20:41');

        foreach ([$late, $early, $other] as $milestone) {
            self::assertTrue($this->milestoneRecords()->add($milestone));
        }

        self::assertEquals([$early, $late], $this->milestoneRecords()->ofUser(TestData::userId(1)));
        self::assertEquals([$other], $this->milestoneRecords()->ofUser(TestData::userId(2)));
        self::assertSame([], $this->milestoneRecords()->ofUser(TestData::userId(3)));
    }

    public function test_FR_GAME_09_every_milestone_can_be_stored_and_read(): void
    {
        $this->givenUsers(TestData::userId(1));
        $all = array_map(static fn (HealthMilestone $milestone): ReachedMilestone => self::reached(1, $milestone, '2026-11-17 12:00:00'), HealthMilestone::cases());

        foreach ($all as $milestone) {
            $this->milestoneRecords()->add($milestone);
        }

        self::assertEquals($all, $this->milestoneRecords()->ofUser(TestData::userId(1)));
    }

    public function test_FR_GAME_09_a_milestone_is_stored_once_and_the_first_time_stays(): void
    {
        $this->givenUsers(TestData::userId(1));
        $first = self::reached(1, HealthMilestone::CarbonMonoxide, '2026-11-14 22:00:00');
        self::assertTrue($this->milestoneRecords()->add($first));

        // Reached again after a later session: nothing is stored, and the answer says so.
        self::assertFalse($this->milestoneRecords()->add(self::reached(1, HealthMilestone::CarbonMonoxide, '2026-11-16 09:00:00')));
        self::assertFalse($this->milestoneRecords()->add($first));

        self::assertEquals([$first], $this->milestoneRecords()->ofUser(TestData::userId(1)));
    }

    public function test_FR_FRND_03_the_milestones_of_several_users_come_by_user_without_their_time(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3), TestData::userId(4));
        $this->milestoneRecords()->add(self::reached(1, HealthMilestone::Circulation, '2026-11-15 08:00:00'));
        $this->milestoneRecords()->add(self::reached(1, HealthMilestone::HeartRate, '2026-11-01 08:20:00'));
        $this->milestoneRecords()->add(self::reached(2, HealthMilestone::HeartRate, '2026-11-02 09:20:00'));
        $this->milestoneRecords()->add(self::reached(4, HealthMilestone::HeartRate, '2026-11-02 09:20:00'));

        // User 3 has reached nothing and is not in the answer. User 4 was not asked for.
        self::assertSame(
            [
                TestData::uuid(1) => [HealthMilestone::HeartRate, HealthMilestone::Circulation],
                TestData::uuid(2) => [HealthMilestone::HeartRate],
            ],
            $this->milestoneRecords()->reachedBy([TestData::userId(2), TestData::userId(1), TestData::userId(3)]),
        );
        self::assertSame([], $this->milestoneRecords()->reachedBy([TestData::userId(3)]));
        self::assertSame([], $this->milestoneRecords()->reachedBy([]));
    }

    public function test_FR_GAME_09_somebody_who_never_chose_has_the_default_settings(): void
    {
        $this->givenUsers(TestData::userId(1));

        self::assertEquals(Settings::defaults(), $this->userSettings()->find(TestData::userId(1)));
        self::assertSame(MilestoneSharing::FromTwoWeeks, Settings::defaults()->milestoneSharing);
    }

    public function test_FR_GAME_09_saved_settings_are_found_and_saving_again_replaces_them_for_that_user_only(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->userSettings()->save(TestData::userId(1), new Settings(MilestoneSharing::All));
        $this->userSettings()->save(TestData::userId(2), new Settings(MilestoneSharing::All));

        $this->userSettings()->save(TestData::userId(1), new Settings(MilestoneSharing::Private));

        self::assertEquals(new Settings(MilestoneSharing::Private), $this->userSettings()->find(TestData::userId(1)));
        self::assertEquals(new Settings(MilestoneSharing::All), $this->userSettings()->find(TestData::userId(2)));
        // The default can be chosen too, after another choice.
        $this->userSettings()->save(TestData::userId(1), new Settings(MilestoneSharing::FromTwoWeeks));
        self::assertEquals(Settings::defaults(), $this->userSettings()->find(TestData::userId(1)));
    }

    public function test_FR_FRND_03_the_settings_of_several_users_come_by_user_with_the_defaults_for_who_never_chose(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $this->userSettings()->save(TestData::userId(2), new Settings(MilestoneSharing::Private));
        $this->userSettings()->save(TestData::userId(3), new Settings(MilestoneSharing::All));

        // Everybody who was asked for is in the answer. User 3 was not asked for.
        self::assertEquals(
            [TestData::uuid(1) => Settings::defaults(), TestData::uuid(2) => new Settings(MilestoneSharing::Private)],
            $this->userSettings()->findAll([TestData::userId(2), TestData::userId(1)]),
        );
        self::assertSame([TestData::uuid(1), TestData::uuid(2)], array_keys($this->userSettings()->findAll([TestData::userId(2), TestData::userId(1)])));
        self::assertSame([], $this->userSettings()->findAll([]));
    }

    private static function reached(int $userNumber, HealthMilestone $milestone, string $reachedAt): ReachedMilestone
    {
        return new ReachedMilestone(TestData::userId($userNumber), $milestone, TestData::time($reachedAt));
    }
}
