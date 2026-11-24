<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Feed;

use SmokeDetector\Application\Feed\FeedEntry;
use SmokeDetector\Application\Feed\ReactionResult;
use SmokeDetector\Application\Feed\ReactionView;
use SmokeDetector\Application\Friends\PublicProfile;

/**
 * Writes the activity feed as the schema Feed of api/openapi.yaml (HLD §4.12), and the answer to a
 * reaction as the schema ReactionResult (HLD §4.13). No entry has a time.
 */
final readonly class FeedResponder
{
    /**
     * @param list<FeedEntry> $entries
     *
     * @return array<string, mixed>
     */
    public function feed(array $entries): array
    {
        return [
            'entries' => array_map(
                static fn (FeedEntry $entry): array => [
                    'id' => $entry->id,
                    'type' => $entry->type->value,
                    'user' => self::profile($entry->user),
                    'mine' => $entry->mine,
                    'level' => $entry->level,
                    'badge' => $entry->badge?->value,
                    'milestone' => $entry->milestone?->value,
                    'opponent' => $entry->opponent === null ? null : self::profile($entry->opponent),
                    'myReaction' => $entry->myReaction?->value,
                    'reactions' => array_map(
                        static fn (ReactionView $reaction): array => ['user' => self::profile($reaction->user), 'reaction' => $reaction->reaction->value],
                        $entry->reactions,
                    ),
                ],
                $entries,
            ),
        ];
    }

    /**
     * @return array{reaction: string, xp: int}
     */
    public function reaction(ReactionResult $result): array
    {
        return ['reaction' => $result->reaction->value, 'xp' => $result->xp];
    }

    /**
     * @return array<string, string> The schema PublicUser.
     */
    private static function profile(PublicProfile $profile): array
    {
        return ['userId' => $profile->userId->value, 'displayName' => $profile->displayName->value, 'avatar' => $profile->avatar->key];
    }
}
