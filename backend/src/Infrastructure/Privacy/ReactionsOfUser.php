<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataEraser;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The eraser of the `feed_reactions` table (FR-ACC-08, HLD §4.13 "Export and deletion"): the
 * reactions the user sent, and the reactions of others to the user's entries.
 *
 * It runs before the eraser of `feed_events` (UserDataTables): the entries of the user still exist,
 * so the reactions to them are found here and not left to the foreign key, which is only the safety
 * net under this.
 */
final readonly class ReactionsOfUser implements UserDataEraser
{
    public function __construct(private Sql $sql)
    {
    }

    public function erase(UserId $userId): void
    {
        $this->sql->execute('DELETE FROM feed_reactions WHERE user_id = ?', [$userId->value]);
        $this->sql->execute(
            'DELETE FROM feed_reactions WHERE event_id IN (SELECT id FROM feed_events WHERE user_id = ?)',
            [$userId->value],
        );
    }
}
