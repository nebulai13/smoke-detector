<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\Friendship;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoFriendLinks;

/**
 * The `friendships` table in the data export (schema ExportedFriend): the friendships and open
 * requests the user is part of, oldest request first. Of the other user the id and nothing else
 * (HLD §4.7 "Export and deletion").
 */
final readonly class FriendsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        return array_map(
            static fn (Friendship $link): array => [
                'userId' => $link->other($userId)->value,
                'status' => $link->isAccepted() ? 'accepted' : 'pending',
                'requestedByMe' => $link->requester->equals($userId),
                'requestedAt' => ExportFormat::time($link->requestedAt),
                'acceptedAt' => ExportFormat::nullableTime($link->acceptedAt),
            ],
            (new PdoFriendLinks($this->sql))->ofUser($userId),
        );
    }
}
