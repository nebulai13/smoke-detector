<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Health;

use DateTimeImmutable;

/**
 * When the cron job last ran through without a failure (HLD §7 "cron heartbeat"). Written by the
 * cron job (`cron:run`), read by GET /health.
 */
interface CronHeartbeat
{
    /**
     * Records a successful run.
     */
    public function beat(DateTimeImmutable $at): void;

    /**
     * Never throws: a heartbeat that cannot be read is one that is not known.
     *
     * @return DateTimeImmutable|null Null when no run was recorded yet (or none can be).
     */
    public function lastBeat(): ?DateTimeImmutable;
}
