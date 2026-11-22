<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use InvalidArgumentException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataEraser;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The eraser for a table whose rows each belong to exactly one user: it deletes the rows whose
 * user column holds the user's id.
 *
 * Not for a table whose rows are shared by several users (a friendship, a duel): deleting such a
 * row takes something from the other user too, so that table needs an eraser of its own.
 */
final readonly class RowsOfUser implements UserDataEraser
{
    /**
     * @param string $table  A table name written in the code. Never a value from outside.
     * @param string $column The column with the user's id.
     */
    public function __construct(
        private Sql $sql,
        private string $table,
        private string $column = 'user_id',
    ) {
        // Names cannot be bound as parameters. They are fixed in the code, and this keeps it so.
        foreach ([$table, $column] as $name) {
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw new InvalidArgumentException('Table and column names are made of a-z, 0-9 and "_" and start with a letter.');
            }
        }
    }

    public function erase(UserId $userId): void
    {
        $this->sql->execute("DELETE FROM `{$this->table}` WHERE `{$this->column}` = ?", [$userId->value]);
    }
}
