<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Recovery;

use DateInterval;
use DateTimeImmutable;

/**
 * Where a user stands on the health milestones since their last session (SRS FR-GAME-09).
 */
final class HealthMilestones
{
    /**
     * All milestones in their order, each with the moment it is reached.
     *
     * A session later than now (a device clock that runs ahead) simply has reached nothing yet.
     *
     * @param DateTimeImmutable $since The last session.
     *
     * @return list<HealthMilestoneState>
     */
    public static function at(DateTimeImmutable $since, DateTimeImmutable $now): array
    {
        $states = [];
        foreach (HealthMilestone::cases() as $milestone) {
            // Adding a time span (PT…M) counts elapsed time since PHP 8.1, so a change between summer
            // and winter time on the way does not shift the moment. modify('+… minutes') would.
            $reachedAt = $since->add(new DateInterval('PT' . $milestone->afterMinutes() . 'M'));
            $states[] = new HealthMilestoneState($milestone, $reachedAt, $now >= $reachedAt);
        }

        return $states;
    }
}
