<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Health;

/**
 * Use case behind GET /health (HLD §7): is the database there, which release is running, and when
 * did the cron job last run.
 */
final readonly class CheckHealth
{
    public function __construct(
        private DatabaseProbe $database,
        private CronHeartbeat $cronHeartbeat,
        /** The deployed release (Settings::$release). Only this value: a use case needs no secrets. */
        private string $release,
    ) {
    }

    public function execute(): HealthReport
    {
        return new HealthReport(
            databaseReachable: $this->database->isReachable(),
            version: $this->release,
            cronLastRunAt: $this->cronHeartbeat->lastBeat(),
        );
    }
}
