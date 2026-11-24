<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataEraser;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The eraser of the `feed_events` table (FR-ACC-08, HLD §4.12 "Export and deletion"): the entries
 * about the user are deleted. An entry of somebody else that names the user as the opponent of a duel
 * stays with its user and forgets the user's id.
 *
 * The foreign keys (ON DELETE CASCADE and SET NULL) are only the safety net under this, as for duels.
 */
final readonly class FeedEventsOfUser implements UserDataEraser
{
    public function __construct(private Sql $sql)
    {
    }

    public function erase(UserId $userId): void
    {
        $this->sql->execute('DELETE FROM feed_events WHERE user_id = ?', [$userId->value]);
        $this->sql->execute('UPDATE feed_events SET other_user_id = NULL WHERE other_user_id = ?', [$userId->value]);
    }
}
