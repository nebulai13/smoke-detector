<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Competition;

use DateTimeImmutable;
use DateTimeZone;
use SmokeDetector\Application\Competition\DayStanding;
use SmokeDetector\Application\Competition\DuelDetail;
use SmokeDetector\Application\Competition\DuelView;
use SmokeDetector\Application\Competition\SideStanding;
use SmokeDetector\Application\Friends\PublicProfile;

/**
 * Writes duels as the schemas Duel, DuelList and DuelDetail of api/openapi.yaml.
 *
 * Built from DuelView and DayStanding only: whole percentages and day levels, never a baseline, a count or a limit, so
 * nothing else of the other user can get into these answers (HLD §4.8 "What the opponent sees").
 */
final readonly class DuelsResponder
{
    /**
     * @param list<DuelView> $duels
     *
     * @return array<string, mixed>
     */
    public function list(array $duels): array
    {
        return ['duels' => array_map($this->duel(...), $duels)];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(DuelDetail $detail): array
    {
        return [
            'duel' => $this->duel($detail->duel),
            'days' => array_map(
                static fn (DayStanding $day): array => ['day' => $day->day, 'mine' => $day->mine?->value, 'theirs' => $day->theirs?->value],
                $detail->days,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function duel(DuelView $duel): array
    {
        return [
            'id' => $duel->id,
            'status' => $duel->status->value,
            'periodDays' => $duel->periodDays,
            'challenger' => $duel->challenger,
            'opponent' => $duel->opponent === null ? null : self::user($duel->opponent),
            'createdAt' => self::time($duel->createdAt),
            'expiresAt' => self::time($duel->expiresAt),
            'startsOn' => $duel->startsOn?->value,
            'endsOn' => $duel->endsOn?->value,
            'mine' => $duel->mine === null ? null : self::side($duel->mine),
            'theirs' => $duel->theirs === null ? null : self::side($duel->theirs),
            'outcome' => $duel->outcome?->value,
            'xp' => $duel->xp,
            'provisional' => $duel->provisional,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function side(SideStanding $side): array
    {
        return [
            // Whole percent: no decimal that could be calculated back to the baseline and the counts.
            'reductionPercent' => $side->reductionPercent,
            'smokeFreeDays' => $side->smokeFreeDays,
            'checkIns' => $side->checkIns,
            'countedDays' => $side->countedDays,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function user(PublicProfile $profile): array
    {
        return ['userId' => $profile->userId->value, 'displayName' => $profile->displayName->value, 'avatar' => $profile->avatar->key];
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
