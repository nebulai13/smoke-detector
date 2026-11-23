<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Recovery;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Domain\Recovery\HealthMilestone;

/**
 * The health milestones all users have reached for good (HLD §4.3 "user_milestones", §4.11): a user
 * reaches a milestone once, and it is never taken back.
 */
interface MilestoneRecords
{
    /**
     * Stores the milestone unless the user has reached it already: the first time stays.
     *
     * @return bool Whether it was stored now.
     */
    public function add(ReachedMilestone $milestone): bool;

    /**
     * @return list<ReachedMilestone> The milestones of the user, in the order of the HealthMilestone cases.
     */
    public function ofUser(UserId $userId): array;

    /**
     * Which milestones these users have reached, without the time: what a friend may see, before
     * the friend's setting is applied (SRS FR-FRND-03).
     *
     * @param list<UserId> $userIds
     *
     * @return array<string, list<HealthMilestone>> By user id, each list in the order of the cases. A
     *                                              user who has reached nothing is not in it.
     */
    public function reachedBy(array $userIds): array;
}
