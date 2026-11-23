<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Settings;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Settings\SaveSettings;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Application\Settings\ShowSettings;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * `GET /me/settings` and `PUT /me/settings` (HLD §4.11 "Settings"; FR-GAME-09): what the user lets
 * friends see of the health milestones.
 */
final class SettingsTest extends LoggingUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_GAME_09_somebody_who_never_chose_shares_the_milestones_from_two_weeks_on(): void
    {
        self::assertEquals(new Settings(MilestoneSharing::FromTwoWeeks), $this->show()->of($this->anna->id));
    }

    public function test_FR_GAME_09_a_choice_is_stored_for_that_user_and_can_be_changed_again(): void
    {
        $bob = $this->givenUser('bob@example.org');

        $saved = $this->save()->execute($this->anna->id, new Settings(MilestoneSharing::Private));

        self::assertEquals(new Settings(MilestoneSharing::Private), $saved);
        self::assertEquals(new Settings(MilestoneSharing::Private), $this->show()->of($this->anna->id));
        self::assertEquals(Settings::defaults(), $this->show()->of($bob->id));

        $this->save()->execute($this->anna->id, new Settings(MilestoneSharing::All));
        self::assertEquals(new Settings(MilestoneSharing::All), $this->show()->of($this->anna->id));
    }

    public function test_FR_ACC_08_an_account_that_was_deleted_in_the_meantime_stores_no_settings(): void
    {
        $this->expectException(AccountNotFound::class);

        $this->save()->execute(TestData::userId(99), new Settings(MilestoneSharing::All));
    }

    private function show(): ShowSettings
    {
        return new ShowSettings($this->userSettings);
    }

    private function save(): SaveSettings
    {
        return new SaveSettings($this->transactions, $this->users, $this->userSettings);
    }
}
