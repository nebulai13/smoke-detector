<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoFeedEvents;

/**
 * The `feed_events` table in the data export (schema ExportedFeedEvent): the entries about the user,
 * oldest first, with the time of each and of an opponent the id only (HLD §4.12 "Export and deletion").
 */
final readonly class FeedEventsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        return array_map(
            static fn (FeedEvent $event): array => [
                'type' => $event->type->value,
                'detail' => $event->detail,
                'opponentId' => $event->otherUserId?->value,
                'createdAt' => ExportFormat::time($event->createdAt),
            ],
            (new PdoFeedEvents($this->sql))->ofUser($userId),
        );
    }
}
