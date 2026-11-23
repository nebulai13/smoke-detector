<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;

/**
 * The final results of the league periods (HLD §4.3 "league_results"): one per user, type and
 * period.
 */
interface LeagueResults
{
    public function find(UserId $userId, CalendarPeriod $period): ?LeagueResult;

    /**
     * Stores the result, replacing the one of the same user, type and period.
     */
    public function save(LeagueResult $result): void;

    /**
     * @return list<LeagueResult> At most $limit results without a rank whose check_at is not after
     *                            $now: the longest waiting first, then by user id, type and key.
     */
    public function unranked(DateTimeImmutable $now, int $limit): array;

    /**
     * @return list<LeagueResult> Every result of the user, in the order they were scored; in the
     *                            same second by type, then by key.
     */
    public function ofUser(UserId $userId): array;
}
