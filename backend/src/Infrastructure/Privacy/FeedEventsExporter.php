<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Feed\Reaction;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoFeedEvents;
use SmokeDetector\Infrastructure\Repository\PdoReactions;

/**
 * The `feed_events` table in the data export (schema ExportedFeedEvent): the entries about the user,
 * oldest first, with the time of each and of an opponent the id only (HLD §4.12 "Export and deletion"),
 * and with the reactions to each: who, the key and the time, oldest first (HLD §4.13).
 */
final readonly class FeedEventsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $events = (new PdoFeedEvents($this->sql))->ofUser($userId);
        $reactions = (new PdoReactions($this->sql))->ofEvents(array_map(static fn (FeedEvent $event): string => $event->id, $events));

        return array_map(
            static fn (FeedEvent $event): array => [
                'type' => $event->type->value,
                'detail' => $event->detail,
                'opponentId' => $event->otherUserId?->value,
                'createdAt' => ExportFormat::time($event->createdAt),
                'reactions' => array_map(
                    static fn (Reaction $reaction): array => [
                        'userId' => $reaction->userId->value,
                        'reaction' => $reaction->key->value,
                        'createdAt' => ExportFormat::time($reaction->createdAt),
                    ],
                    $reactions[$event->id] ?? [],
                ),
            ],
            $events,
        );
    }
}
