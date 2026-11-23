<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Recovery\MilestoneRecords;
use SmokeDetector\Application\Recovery\ReachedMilestone;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoMilestoneRecords;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Infrastructure\Repository\PdoUserSettings;
use SmokeDetector\Tests\Support\Contract\MilestoneAndSettingsContract;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestData;

/**
 * The milestone records and the user settings against the real database: the contract every
 * implementation passes, and what only the database can show (the columns, and that no row outlives
 * its account).
 */
final class PdoMilestoneAndSettingsTest extends IntegrationTestCase
{
    use MilestoneAndSettingsContract;

    private Database $database;

    protected function setUp(): void
    {
        // A connection of its own, as the application has: the test's own connection looks at the
        // rows from outside.
        $this->database = $this->newDatabase();
    }

    public function test_FR_GAME_09_milestones_and_settings_are_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsers(TestData::userId(1));

        $this->milestoneRecords()->add(new ReachedMilestone(TestData::userId(1), HealthMilestone::HeartDiseaseHalved, TestData::time('2027-11-01 08:00:41')));
        $this->userSettings()->save(TestData::userId(1), new Settings(MilestoneSharing::FromTwoWeeks));

        self::assertSame(
            [['user_id' => TestData::uuid(1), 'milestone_key' => 'heart_disease_halved', 'reached_at' => '2027-11-01 08:00:41']],
            $this->sql()->rows('SELECT * FROM user_milestones'),
        );
        self::assertSame(
            [['user_id' => TestData::uuid(1), 'milestone_sharing' => 'from_two_weeks']],
            $this->sql()->rows('SELECT * FROM user_settings'),
        );
    }

    public function test_FR_ACC_08_no_milestone_and_no_setting_outlives_its_account(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        foreach ([1, 2] as $number) {
            $this->milestoneRecords()->add(new ReachedMilestone(TestData::userId($number), HealthMilestone::HeartRate, TestData::time('2026-11-14 10:20:00')));
            $this->userSettings()->save(TestData::userId($number), new Settings(MilestoneSharing::All));
        }

        $this->sql()->execute('DELETE FROM users WHERE id = ?', [TestData::uuid(1)]);

        self::assertSame([TestData::uuid(2)], $this->sql()->column('SELECT user_id FROM user_milestones'));
        self::assertSame([TestData::uuid(2)], $this->sql()->column('SELECT user_id FROM user_settings'));
    }

    protected function milestoneRecords(): MilestoneRecords
    {
        return new PdoMilestoneRecords(new Sql($this->database));
    }

    protected function userSettings(): UserSettings
    {
        return new PdoUserSettings(new Sql($this->database));
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
