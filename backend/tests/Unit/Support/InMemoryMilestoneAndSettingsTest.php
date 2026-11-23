<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Recovery\MilestoneRecords;
use SmokeDetector\Application\Recovery\ReachedMilestone;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Tests\Support\Contract\MilestoneAndSettingsContract;
use SmokeDetector\Tests\Support\InMemory\InMemoryMilestoneRecords;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\InMemory\InMemoryUserSettings;
use SmokeDetector\Tests\Support\TestData;

/**
 * The in-memory milestone records and user settings behave like the PDO ones: both run the same
 * contract tests (the PDO ones in tests/Integration/Infrastructure/Repository).
 */
final class InMemoryMilestoneAndSettingsTest extends TestCase
{
    use MilestoneAndSettingsContract;

    private InMemoryTransactionRunner $transactions;
    private InMemoryMilestoneRecords $milestoneRecords;
    private InMemoryUserSettings $userSettings;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->milestoneRecords = new InMemoryMilestoneRecords($this->transactions);
        $this->userSettings = new InMemoryUserSettings($this->transactions);
    }

    public function test_what_a_failed_transaction_stored_is_undone_like_in_the_database(): void
    {
        $kept = new ReachedMilestone(TestData::userId(1), HealthMilestone::HeartRate, TestData::time('2026-11-14 10:20:00'));
        $this->milestoneRecords->add($kept);
        $this->userSettings->save(TestData::userId(1), new Settings(MilestoneSharing::All));

        $passedOn = null;
        try {
            $this->transactions->run(function (): void {
                $this->milestoneRecords->add(new ReachedMilestone(TestData::userId(1), HealthMilestone::CarbonMonoxide, TestData::time('2026-11-14 22:00:00')));
                $this->userSettings->save(TestData::userId(1), new Settings(MilestoneSharing::Private));
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $failure) {
            $passedOn = $failure->getMessage();
        }

        self::assertSame('failed', $passedOn);
        self::assertEquals([$kept], $this->milestoneRecords->all());
        self::assertEquals(new Settings(MilestoneSharing::All), $this->userSettings->find(TestData::userId(1)));
    }

    protected function milestoneRecords(): MilestoneRecords
    {
        return $this->milestoneRecords;
    }

    protected function userSettings(): UserSettings
    {
        return $this->userSettings;
    }

    protected function givenUsers(UserId ...$ids): void
    {
        // In memory, rows can exist without an account.
    }
}
