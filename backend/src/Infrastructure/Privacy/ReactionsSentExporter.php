<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `feed_reactions` table in the data export, member reactionsSent (schema ExportedReaction): the
 * reactions the user sent, oldest first, each with the id of the user whose entry it was (HLD §4.13
 * "Export and deletion"). The reactions to the user's own entries are exported with each entry
 * (FeedEventsExporter).
 */
final readonly class ReactionsSentExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $rows = $this->sql->rows(
            'SELECT e.user_id AS owner_id, r.reaction, r.created_at FROM feed_reactions r JOIN feed_events e ON e.id = r.event_id '
            . 'WHERE r.user_id = ? ORDER BY r.created_at, r.event_id',
            [$userId->value],
        );

        return array_map(static function (array $columns): array {
            $row = new Row($columns);

            return ['userId' => $row->string('owner_id'), 'reaction' => $row->string('reaction'), 'createdAt' => ExportFormat::time($row->time('created_at'))];
        }, $rows);
    }
}
