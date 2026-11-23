<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Health;

use DateTimeImmutable;

/**
 * The state of the service at one moment.
 */
final readonly class HealthReport
{
    public function __construct(
        public bool $databaseReachable,
        /** The deployed release (git SHA). */
        public string $version,
        /** When the cron job last ran through without a failure, in UTC. Null when it has not yet. */
        public ?DateTimeImmutable $cronLastRunAt,
    ) {
    }

    public function isHealthy(): bool
    {
        return $this->databaseReachable;
    }
}
