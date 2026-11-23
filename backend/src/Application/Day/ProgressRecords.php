<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;

/**
 * The progress of all users (HLD §4.3 "user_progress").
 */
interface ProgressRecords
{
    public function find(UserId $userId): ?ProgressRecord;

    /**
     * Stores the progress, replacing what was stored for that user.
     */
    public function save(ProgressRecord $record): void;

    /**
     * The users the cron job has work for: whose next day to finalize or next weekly baseline is
     * due at $now or earlier.
     *
     * @return list<UserId> The longest waiting first (by the earlier of the two instants), then by
     *                      user id. At most $limit.
     */
    public function due(DateTimeImmutable $now, int $limit): array;
}
