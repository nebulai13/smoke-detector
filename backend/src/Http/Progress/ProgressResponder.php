<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Progress;

use DateTimeImmutable;
use DateTimeZone;
use SmokeDetector\Application\Badge\AwardedBadge;
use SmokeDetector\Application\Recovery\HealthView;
use SmokeDetector\Domain\Recovery\HealthMilestoneState;

/**
 * Writes the user's own badges and health milestones as the schemas Badges and HealthMilestones of
 * api/openapi.yaml (HLD §4.10). Both are for the user alone: a friend sees badge keys only
 * (FriendsResponder).
 */
final readonly class ProgressResponder
{
    /**
     * @param list<AwardedBadge> $badges
     *
     * @return array<string, mixed>
     */
    public function badges(array $badges): array
    {
        return [
            'badges' => array_map(
                static fn (AwardedBadge $badge): array => ['key' => $badge->badge->value, 'awardedAt' => self::time($badge->awardedAt)],
                $badges,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function healthMilestones(HealthView $view): array
    {
        return [
            'since' => $view->since === null ? null : self::time($view->since),
            'vapingOnly' => $view->vapingOnly,
            'milestones' => array_map(
                static fn (HealthMilestoneState $state): array => [
                    'key' => $state->milestone->value,
                    'reachedAt' => self::time($state->reachedAt),
                    'reached' => $state->reached,
                ],
                $view->milestones,
            ),
        ];
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
