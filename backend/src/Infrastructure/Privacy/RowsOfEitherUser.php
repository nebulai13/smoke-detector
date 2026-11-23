<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use InvalidArgumentException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataEraser;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The eraser for a table whose rows each belong to two users, one in each of two columns (a
 * friendship, a block): it deletes every row in which the user is on either side. The other user
 * loses the row too, which is what deleting an account means for them (FR-ACC-08).
 */
final readonly class RowsOfEitherUser implements UserDataEraser
{
    /**
     * @param string $table   A table name written in the code. Never a value from outside.
     * @param string $column  One column with a user's id.
     * @param string $another The other column with a user's id.
     */
    public function __construct(
        private Sql $sql,
        private string $table,
        private string $column,
        private string $another,
    ) {
        // Names cannot be bound as parameters. They are fixed in the code, and this keeps it so.
        foreach ([$table, $column, $another] as $name) {
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw new InvalidArgumentException('Table and column names are made of a-z, 0-9 and "_" and start with a letter.');
            }
        }
    }

    public function erase(UserId $userId): void
    {
        // One statement per column, each on its index, instead of one OR.
        foreach ([$this->column, $this->another] as $column) {
            $this->sql->execute("DELETE FROM `{$this->table}` WHERE `{$column}` = ?", [$userId->value]);
        }
    }
}
