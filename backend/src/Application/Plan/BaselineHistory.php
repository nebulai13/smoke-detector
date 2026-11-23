<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Application\Account\UserId;

/**
 * Every baseline a user ever had (HLD §4.3 "baseline_history", FR-PLAN-02).
 *
 * Append-only on purpose: there is no method that changes or removes an entry, because past values
 * are never recalculated (NFR-04). The entries go only when the account is deleted, which is the
 * business of the user data registry.
 */
interface BaselineHistory
{
    /**
     * @throws BaselineAlreadyRecorded When the user already has an entry valid from that day. The
     *                                 stored entry stays as it is.
     */
    public function append(BaselineEntry $entry): void;

    /**
     * The current baseline: the entry with the latest validity date.
     */
    public function latest(UserId $userId): ?BaselineEntry;

    /**
     * What the user stated in the onboarding.
     */
    public function starting(UserId $userId): ?BaselineEntry;

    /**
     * @return list<BaselineEntry> Oldest first.
     */
    public function ofUser(UserId $userId): array;
}
