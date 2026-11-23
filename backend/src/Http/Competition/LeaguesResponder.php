<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Competition;

use SmokeDetector\Application\Competition\LeagueEntry;
use SmokeDetector\Application\Competition\LeagueView;
use SmokeDetector\Application\Competition\XpLeagueEntry;
use SmokeDetector\Application\Competition\XpLeagueView;
use SmokeDetector\Application\Friends\PublicProfile;

/**
 * Writes leagues as the schemas League and XpLeague of api/openapi.yaml.
 *
 * Built from LeagueView and XpLeagueView only: whole percentages, ranks, standings and the tie-breakers, never
 * a baseline, a count or a total, so nothing else of another user can get into these answers
 * (HLD §4.9 "What a user sees").
 */
final readonly class LeaguesResponder
{
    /**
     * @return array<string, mixed>
     */
    public function league(LeagueView $league): array
    {
        $award = $league->award;

        return [
            'type' => $league->period->type->value,
            'period' => $league->period->key,
            'startsOn' => $league->period->first()->value,
            'endsOn' => $league->period->last()->value,
            'final' => $league->final,
            'entries' => array_map(
                static fn (LeagueEntry $entry): array => [
                    'rank' => $entry->rank,
                    // Of a friend while the league is not final, instead of a rank (ShowLeague).
                    'standing' => $entry->standing?->value,
                    'user' => self::user($entry->user),
                    'you' => $entry->you,
                    // Whole percent. Null for a friend while it may not be shown (ShowLeague).
                    'reductionPercent' => $entry->reductionPercent,
                    'smokeFreeDays' => $entry->smokeFreeDays,
                    'checkIns' => $entry->checkIns,
                    'countedDays' => $entry->countedDays,
                ],
                $league->entries,
            ),
            'award' => $award === null ? null : ['rank' => $award->rank, 'participants' => $award->participants, 'xp' => $award->xp],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function xpLeague(XpLeagueView $league): array
    {
        return [
            'period' => $league->period->key,
            'startsOn' => $league->period->first()->value,
            'endsOn' => $league->period->last()->value,
            'entries' => array_map(
                static fn (XpLeagueEntry $entry): array => ['rank' => $entry->rank, 'user' => self::user($entry->user), 'you' => $entry->you, 'xp' => $entry->xp],
                $league->entries,
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function user(PublicProfile $profile): array
    {
        return ['userId' => $profile->userId->value, 'displayName' => $profile->displayName->value, 'avatar' => $profile->avatar->key];
    }
}
