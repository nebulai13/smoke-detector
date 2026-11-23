<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Recovery\MilestoneSharing;

/**
 * A friend as the other one sees them (FR-FRND-03, HLD §4.7 "What friends see"): the public
 * profile, since when they are friends, and the aggregates as they were stored when the friend's
 * last day became final.
 *
 * Deliberately plain numbers and no StreakState: that has the streak freezes, which a friend never
 * sees. Nothing else of the progress can get into a view this way.
 */
final readonly class FriendView
{
    /**
     * @param list<Badge>           $badges           Which badges the friend holds, in the order of the
     *                                                cases, and never when they were earned: the day of a
     *                                                first smoke-free day would be a day with a known count.
     * @param list<HealthMilestone> $healthMilestones The health milestones the friend has reached and
     *                                                lets friends see, in the order of the cases, and
     *                                                never when: the time would tell the day of a session.
     */
    public function __construct(
        public PublicProfile $user,
        /** When the request was accepted. */
        public DateTimeImmutable $since,
        public int $level,
        public int $xp,
        public int $checkInCurrent,
        public int $checkInLongest,
        public int $underLimitCurrent,
        public int $underLimitLongest,
        public array $badges,
        public array $healthMilestones,
    ) {
    }

    /**
     * @param ProgressRecord|null   $record           The friend's stored progress (never a projection), or
     *                                                null before the onboarding: then everything is zero.
     * @param list<Badge>           $badges           The badges the friend holds (BadgeRecords::heldBy()).
     * @param list<HealthMilestone> $healthMilestones The milestones the friend has reached (MilestoneRecords::reachedBy()).
     * @param MilestoneSharing      $sharing          The friend's own setting: it decides which of them are shown.
     */
    public static function of(User $friend, DateTimeImmutable $since, ?ProgressRecord $record, array $badges, array $healthMilestones, MilestoneSharing $sharing): self
    {
        $progress = $record->progress ?? UserProgress::initial();
        $streaks = $progress->streaks;

        return new self(
            PublicProfile::of($friend),
            $since,
            $record->level ?? 0,
            $progress->totalXp,
            $streaks->checkInCurrent,
            $streaks->checkInLongest,
            $streaks->underLimitCurrent,
            $streaks->underLimitLongest,
            $badges,
            array_values(array_filter($healthMilestones, $sharing->shows(...))),
        );
    }
}
