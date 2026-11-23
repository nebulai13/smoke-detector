<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataEraser;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The eraser of the `duels` table (FR-ACC-08, HLD §4.8 "Export and deletion"). A duel belongs to
 * both users, and the other one keeps it:
 *
 * 1. the user's proposals are deleted, in either direction;
 * 2. the user's running duels become forfeits: no winner, no XP for the one who is left;
 * 3. the user's side of every duel that is left is emptied: the id, the first day and the result;
 * 4. a duel that belongs to nobody any more is deleted.
 *
 * The foreign keys (ON DELETE SET NULL) are only the safety net under this.
 */
final readonly class DuelsOfUser implements UserDataEraser
{
    /** The columns of a side besides its id, without the prefix "challenger_" or "opponent_". */
    private const array SIDE = ['start', 'b0_tenths', 'actual_tenths', 'expected_tenths', 'smoke_free_days', 'check_ins', 'xp'];

    public function __construct(private Sql $sql)
    {
    }

    public function erase(UserId $userId): void
    {
        // One statement per column, each on its index, instead of one OR.
        foreach (['challenger', 'opponent'] as $side) {
            $this->sql->execute("DELETE FROM duels WHERE {$side}_id = ? AND status = 'proposed'", [$userId->value]);
        }
        foreach (['challenger', 'opponent'] as $side) {
            $this->sql->execute("UPDATE duels SET status = 'forfeited' WHERE {$side}_id = ? AND status = 'active'", [$userId->value]);
        }
        foreach (['challenger', 'opponent'] as $side) {
            $emptied = array_map(static fn (string $column): string => "{$side}_{$column} = NULL", ['id', ...self::SIDE]);
            $this->sql->execute('UPDATE duels SET ' . implode(', ', $emptied) . " WHERE {$side}_id = ?", [$userId->value]);
        }
        $this->sql->execute('DELETE FROM duels WHERE challenger_id IS NULL AND opponent_id IS NULL');
    }
}
