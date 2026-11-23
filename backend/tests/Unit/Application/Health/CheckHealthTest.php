<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Health;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Health\CheckHealth;
use SmokeDetector\Application\Health\CronHeartbeat;
use SmokeDetector\Application\Health\DatabaseProbe;
use SmokeDetector\Tests\Support\SettingsFixture;
use SmokeDetector\Tests\Support\TestData;

/**
 * What GET /health reports (HLD §7): the database, the release, and the cron heartbeat.
 */
final class CheckHealthTest extends TestCase
{
    public function test_with_a_reachable_database_the_service_is_healthy(): void
    {
        $report = $this->checkHealth(databaseReachable: true)->execute();

        self::assertTrue($report->databaseReachable);
        self::assertTrue($report->isHealthy());
    }

    public function test_without_the_database_the_service_is_not_healthy(): void
    {
        $report = $this->checkHealth(databaseReachable: false)->execute();

        self::assertFalse($report->databaseReachable);
        self::assertFalse($report->isHealthy());
    }

    public function test_the_version_is_the_release_of_the_settings(): void
    {
        // The release alone is handed in, not the settings with every secret in them.
        $checkHealth = new CheckHealth(self::probe(true), self::heartbeat(null), SettingsFixture::settings(['release' => '32ce3f8ed5ea'])->release);

        self::assertSame('32ce3f8ed5ea', $checkHealth->execute()->version);
    }

    public function test_the_version_is_reported_even_when_the_database_is_down(): void
    {
        self::assertSame('test-release', $this->checkHealth(databaseReachable: false)->execute()->version);
    }

    public function test_the_cron_heartbeat_is_unknown_until_the_cron_job_has_run(): void
    {
        self::assertNull($this->checkHealth(databaseReachable: true)->execute()->cronLastRunAt);
    }

    public function test_the_time_of_the_last_successful_cron_run_is_reported(): void
    {
        $lastRun = TestData::time('2026-11-17 11:59:07');

        $report = $this->checkHealth(databaseReachable: true, cronLastRunAt: $lastRun)->execute();

        self::assertEquals($lastRun, $report->cronLastRunAt);
        // Reported, not judged: an old heartbeat does not make the service unhealthy (yet).
        self::assertTrue($report->isHealthy());
    }

    public function test_the_cron_heartbeat_is_reported_even_when_the_database_is_down(): void
    {
        $lastRun = TestData::time('2026-11-17 11:59:07');

        self::assertEquals($lastRun, $this->checkHealth(databaseReachable: false, cronLastRunAt: $lastRun)->execute()->cronLastRunAt);
    }

    private function checkHealth(bool $databaseReachable, ?DateTimeImmutable $cronLastRunAt = null): CheckHealth
    {
        return new CheckHealth(self::probe($databaseReachable), self::heartbeat($cronLastRunAt), SettingsFixture::settings()->release);
    }

    private static function heartbeat(?DateTimeImmutable $lastBeat): CronHeartbeat
    {
        return new readonly class ($lastBeat) implements CronHeartbeat {
            public function __construct(private ?DateTimeImmutable $lastBeat)
            {
            }

            public function beat(DateTimeImmutable $at): void
            {
            }

            public function lastBeat(): ?DateTimeImmutable
            {
                return $this->lastBeat;
            }
        };
    }

    private static function probe(bool $reachable): DatabaseProbe
    {
        return new readonly class ($reachable) implements DatabaseProbe {
            public function __construct(private bool $reachable)
            {
            }

            public function isReachable(): bool
            {
                return $this->reachable;
            }
        };
    }
}
